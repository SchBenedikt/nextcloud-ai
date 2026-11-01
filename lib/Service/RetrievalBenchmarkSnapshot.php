<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\GenerationChunkRepository;
use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Vector\{GenerationEmbeddingProvider, QdrantVectorStore, SearchFilters, VectorGeneration, VectorStoreException};
use OCP\IUserManager;
use OCP\IDBConnection;

/** Explicit administrator CLI operations; not part of an interactive chat path. */
final class RetrievalBenchmarkSnapshot {
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(
        private ChunkMapper $chunks,
        private AppConfig $config,
        private EmbeddingProvider $embeddings,
        private Searcher $searcher,
        private IUserManager $users,
        private RetrievalBenchmark $benchmark,
        private IDBConnection $db,
        private VectorGenerationRepository $generations,
        private GenerationChunkRepository $generationChunks,
        private GenerationEmbeddingProvider $generationEmbeddings,
        private QdrantVectorStore $qdrant,
    ) {}

    public function export(string $user, string $directory, string $queryFile): void {
        $previousUser = $this->config->userId();
        $created = false;
        $mask = umask(0077);
        try {
            $this->selectUser($user);
            if (!is_file($queryFile) || filesize($queryFile) > 4194304) throw new \RuntimeException('Missing or oversized query seed file.');
            $queries = json_decode(file_get_contents($queryFile), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($queries) || !array_is_list($queries) || count($queries) < 1 || count($queries) > 100) {
                throw new \RuntimeException('Query seeds must contain between 1 and 100 queries.');
            }
            $configurationHash = $this->configurationHash($user);
            $identity = $this->identity();
            $revision = $this->config->get('search_revision');
            // Validate all seed inputs before any inference request.
            foreach ($queries as &$query) {
                if (!is_array($query) || !is_string($query['text'] ?? null) || trim($query['text']) === '' || strlen($query['text']) > 16000
                    || !is_string($query['id'] ?? null) || $query['id'] === '' || !is_string($query['category'] ?? null)
                    || !is_array($query['relevance'] ?? null) || $query['relevance'] === []
                    || (isset($query['user']) && $query['user'] !== $user)) {
                    throw new \RuntimeException('Invalid query seed or wrong tenant.');
                }
                $query['user'] = $user;
                $query['vector'] = [1.0]; // Shape validation before any inference.
            }
            unset($query);
            $this->benchmark->validateQueries($queries);
            // mkdir is exclusive; never overwrite an existing export, even an empty one.
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Export directory must be new and writable.');
            $created = true;
            $file = $this->openNew($directory . '/chunks.jsonl');
            try {
                foreach ($this->rows($user) as $row) $this->write($file, $this->encode($row) . "\n");
            } finally { fclose($file); }
            $corpusHash = hash_file('sha256', $directory . '/chunks.jsonl');
            $this->assertSnapshot($user, $identity, $corpusHash, $revision, $configurationHash);
            foreach ($queries as &$query) {
                $query['user'] = $user;
                $query['vector'] = $this->queryVector($query['text'], $user);
            }
            unset($query);
            $this->assertSnapshot($user, $identity, $corpusHash, $revision, $configurationHash);
            $this->writeNew($directory . '/queries.json', $this->encode($queries) . "\n");
            $manifest = ['version' => 1, 'corpus_sha256' => $corpusHash,
                'created_at' => gmdate('c'), 'snapshot_format' => 'eva-sql-v1', 'snapshot_user' => $user,
                'declared_configuration' => $identity, 'search_revision' => $revision, 'configuration_sha256' => $configurationHash,
                'embedding_provenance' => 'legacy_unverified', 'vector_migration_eligible' => false,
                'consistency' => 'quiesced-index-with-before-after-fingerprints'];
            $this->writeNew($directory . '/manifest.json', $this->encode($manifest) . "\n");
            $dataset = $this->benchmark->dataset($directory);
            // Validate exported rows and qrels without claiming a retrieval-quality run.
            $emptyRuns = array_map(static fn(array $query): array => ['query_id' => $query['id'], 'ranking' => [], 'duration_ms' => 0,
                'corpus_sha256' => $dataset['corpus_sha256'], 'queries_sha256' => $dataset['queries_sha256']], $queries);
            $this->benchmark->evaluate($dataset, $emptyRuns, 'snapshot_validation');
        } catch (\Throwable $e) {
            if ($created) {
                foreach (['manifest.json', 'queries.json', 'chunks.jsonl'] as $name) @unlink($directory . '/' . $name);
                @rmdir($directory);
            }
            throw $e;
        } finally {
            umask($mask);
            $this->config->setUserId($previousUser);
        }
    }

    public function record(string $user, string $directory, string $outputFile): void {
        $previousUser = $this->config->userId();
        $mask = umask(0077);
        $file = null;
        $created = false;
        try {
            $this->selectUser($user);
            $dataset = $this->benchmark->dataset($directory);
            $manifest = $dataset['manifest'];
            if (($manifest['snapshot_format'] ?? '') !== 'eva-sql-v1' || ($manifest['snapshot_user'] ?? '') !== $user
                || !is_array($manifest['declared_configuration'] ?? null) || count($dataset['queries']) > 100) {
                throw new \RuntimeException('Run requires an EVA SQL snapshot for the selected user.');
            }
            foreach ($dataset['queries'] as $query) {
                if ($query['user'] !== $user || !is_string($query['text'] ?? null) || strlen($query['text']) > 16000 || trim($query['text']) === '') {
                    throw new \RuntimeException('Snapshot query text is missing or belongs to another user.');
                }
            }
            $identity = $manifest['declared_configuration'];
            $revision = (string)($manifest['search_revision'] ?? '');
            $configurationHash = (string)($manifest['configuration_sha256'] ?? '');
            $this->assertSnapshot($user, $identity, $dataset['corpus_sha256'], $revision, $configurationHash);
            $file = $this->openNew($outputFile);
            $created = true;
            foreach ($dataset['queries'] as $query) {
                // Reject a changed/nondeterministic query embedding space.
                $preflightStart = hrtime(true);
                $vector = $this->queryVector($query['text'], $user);
                $preflightMs = (hrtime(true) - $preflightStart) / 1_000_000;
                if ($vector !== array_map('floatval', $query['vector'])) throw new \RuntimeException('Query embedding differs from the frozen snapshot.');
                $start = hrtime(true);
                $results = $this->searcher->searchWithQueryVector($user, $query['text'], 20, $vector, $query['scope'] ?? null);
                $elapsed = (hrtime(true) - $start) / 1_000_000;
                $run = ['query_id' => $query['id'], 'corpus_sha256' => $dataset['corpus_sha256'],
                    'queries_sha256' => $dataset['queries_sha256'], 'ranking' => array_map(static fn(array $row): int => (int)$row['chunkId'], $results),
                    'duration_ms' => $elapsed, 'backend' => 'eva_sql_legacy',
                    'embedding_preflight' => true, 'embedding_preflight_ms' => $preflightMs,
                    'query_vector_mode' => 'frozen', 'cache_state' => 'embedding_cache_not_required'];
                $this->write($file, $this->encode($run) . "\n");
            }
            $this->assertSnapshot($user, $identity, $dataset['corpus_sha256'], $revision, $configurationHash);
            if (hash_file('sha256', $directory . '/queries.json') !== $dataset['queries_sha256']) throw new \RuntimeException('Snapshot queries changed during the run.');
            fclose($file); $file = null;
            // Validate recorded identities and reject isolation violations before success.
            $report = $this->benchmark->evaluate($dataset, $this->benchmark->readRuns($outputFile), 'eva_sql_legacy');
            if (!$report['isolation_passed']) throw new \RuntimeException('Live retrieval returned isolation violations.');
        } catch (\Throwable $e) {
            if (is_resource($file)) fclose($file);
            if ($created) @unlink($outputFile);
            throw $e;
        } finally {
            umask($mask);
            $this->config->setUserId($previousUser);
        }
    }

    /** Export one explicitly selected user's active, verified Qdrant generation. */
    public function exportQdrant(string $user, string $directory, string $queryFile): void {
        $previousUser = $this->config->userId();
        $created = false;
        $mask = umask(0077);
        try {
            $this->selectUser($user);
            $generation = $this->activeQdrantGeneration($user);
            $target = (string)$this->generations->target($user, $generation->id);
            if (!is_file($queryFile) || filesize($queryFile) > 4194304) throw new \RuntimeException('Missing or oversized query seed file.');
            $queries = json_decode(file_get_contents($queryFile), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($queries) || !array_is_list($queries) || count($queries) < 1 || count($queries) > 100) {
                throw new \RuntimeException('Query seeds must contain between 1 and 100 queries.');
            }
            $configurationHash = $this->configurationHash($user);
            $revision = $this->config->get('search_revision');
            foreach ($queries as &$query) {
                if (!is_array($query) || !is_string($query['text'] ?? null) || trim($query['text']) === '' || strlen($query['text']) > 16000
                    || !is_string($query['id'] ?? null) || $query['id'] === '' || !is_string($query['category'] ?? null)
                    || !is_array($query['relevance'] ?? null) || $query['relevance'] === []
                    || (isset($query['user']) && $query['user'] !== $user)) throw new \RuntimeException('Invalid query seed or wrong tenant.');
                $query['user'] = $user;
                $query['vector'] = array_fill(0, $generation->dimension, 1.0); // Validate shape before inference.
            }
            unset($query);
            $this->benchmark->validateQueries($queries);
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Export directory must be new and writable.');
            $created = true;
            $file = $this->openNew($directory . '/chunks.jsonl');
            try { foreach ($this->generationRows($user, $generation) as $row) $this->write($file, $this->encode($row) . "\n"); }
            finally { fclose($file); }
            $corpusHash = hash_file('sha256', $directory . '/chunks.jsonl');
            $this->assertQdrantSnapshot($user, $generation, $target, $corpusHash, $revision, $configurationHash);
            foreach ($queries as &$query) {
                $query['vector'] = $this->generationQueryVector($generation, $query['text']);
            }
            unset($query);
            $this->assertQdrantSnapshot($user, $generation, $target, $corpusHash, $revision, $configurationHash);
            $this->writeNew($directory . '/queries.json', $this->encode($queries) . "\n");
            $manifest = ['version' => 1, 'corpus_sha256' => $corpusHash, 'created_at' => gmdate('c'),
                'snapshot_format' => 'eva-qdrant-generation-v1', 'snapshot_user' => $user,
                'generation_id' => $generation->id, 'generation_identity' => $generation->toRow(),
                'target_sha256' => $target,
                'retrieval_backend' => 'qdrant_generation', 'embedding_provenance' => 'generation_pinned_verified',
                'vector_migration_eligible' => true, 'configuration_sha256' => $configurationHash,
                'search_revision' => $revision, 'consistency' => 'quiesced-generation-with-before-after-fingerprints'];
            $this->writeNew($directory . '/manifest.json', $this->encode($manifest) . "\n");
            $dataset = $this->benchmark->dataset($directory);
            $emptyRuns = array_map(static fn(array $query): array => ['query_id' => $query['id'], 'ranking' => [], 'duration_ms' => 0,
                'corpus_sha256' => $dataset['corpus_sha256'], 'queries_sha256' => $dataset['queries_sha256']], $queries);
            $this->benchmark->evaluate($dataset, $emptyRuns, 'snapshot_validation');
        } catch (\Throwable $e) {
            if ($created) {
                foreach (['manifest.json', 'queries.json', 'chunks.jsonl'] as $name) @unlink($directory . '/' . $name);
                @rmdir($directory);
            }
            throw $e;
        } finally {
            umask($mask);
            $this->config->setUserId($previousUser);
        }
    }

    /** Record Qdrant dense or consented native-hybrid retrieval against a frozen generation. */
    public function recordQdrant(string $user, string $directory, string $outputFile): void {
        $previousUser = $this->config->userId();
        $mask = umask(0077);
        $file = null;
        $created = false;
        try {
            $this->selectUser($user);
            $dataset = $this->benchmark->dataset($directory);
            $manifest = $dataset['manifest'];
            if (($manifest['snapshot_format'] ?? '') !== 'eva-qdrant-generation-v1' || ($manifest['snapshot_user'] ?? '') !== $user
                || !is_string($manifest['generation_id'] ?? null) || !is_array($manifest['generation_identity'] ?? null)
                || count($dataset['queries']) > 100) throw new \RuntimeException('Run requires a Qdrant generation snapshot for the selected user.');
            $generation = $this->activeQdrantGeneration($user);
            if ($generation->id !== $manifest['generation_id'] || $generation->toRow() !== $manifest['generation_identity']
                || $this->generations->target($user, $generation->id) !== ($manifest['target_sha256'] ?? null)
                || $this->configurationHash($user) !== ($manifest['configuration_sha256'] ?? null)) {
                throw new \RuntimeException('The active generation or its configuration changed after snapshot export.');
            }
            foreach ($dataset['queries'] as $query) {
                if (($query['user'] ?? null) !== $user || !is_string($query['text'] ?? null) || strlen($query['text']) > 16000
                    || trim($query['text']) === '') throw new \RuntimeException('Snapshot query text is missing or belongs to another user.');
            }
            $this->assertQdrantSnapshot($user, $generation, (string)$manifest['target_sha256'], $dataset['corpus_sha256'],
                (string)$manifest['search_revision'], (string)$manifest['configuration_sha256']);
            $target = (string)$manifest['target_sha256'];
            $guard = fn() => $this->assertActiveQdrantGeneration($user, $generation);
            $store = $this->qdrant->forTarget($target, $guard);
            $file = $this->openNew($outputFile);
            $created = true;
            foreach ($dataset['queries'] as $query) {
                $preflightStart = hrtime(true);
                $vector = $this->generationQueryVector($generation, $query['text']);
                $preflightMs = (hrtime(true) - $preflightStart) / 1_000_000;
                if ($vector !== array_map('floatval', $query['vector'])) throw new \RuntimeException('Query embedding differs from the frozen generation snapshot.');
                $filters = new SearchFilters($query['scope'] ?? null);
                $started = hrtime(true);
                $hits = $generation->includeTextPayload
                    ? $store->searchHybrid($generation, $query['text'], $vector, 20, $filters)
                    : $store->search($generation, $vector, 20, $filters);
                $guard();
                $rows = $this->generationChunks->searchRows($user, $generation, $hits);
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                $run = ['query_id' => $query['id'], 'corpus_sha256' => $dataset['corpus_sha256'],
                    'queries_sha256' => $dataset['queries_sha256'], 'ranking' => array_map(static fn(array $row): int => (int)$row['id'], $rows),
                    'duration_ms' => $elapsed, 'backend' => $generation->includeTextPayload ? 'eva_qdrant_hybrid' : 'eva_qdrant_dense',
                    'embedding_preflight' => true, 'embedding_preflight_ms' => $preflightMs,
                    'query_vector_mode' => 'frozen_generation', 'cache_state' => 'generation_cache_not_required'];
                $this->write($file, $this->encode($run) . "\n");
            }
            $this->assertQdrantSnapshot($user, $generation, $target, $dataset['corpus_sha256'],
                (string)$manifest['search_revision'], (string)$manifest['configuration_sha256']);
            if (hash_file('sha256', $directory . '/queries.json') !== $dataset['queries_sha256']) throw new \RuntimeException('Snapshot queries changed during the run.');
            fclose($file); $file = null;
            $backend = $generation->includeTextPayload ? 'eva_qdrant_hybrid' : 'eva_qdrant_dense';
            $report = $this->benchmark->evaluate($dataset, $this->benchmark->readRuns($outputFile), $backend);
            if (!$report['isolation_passed']) throw new \RuntimeException('Live Qdrant retrieval returned isolation violations.');
        } catch (\Throwable $e) {
            if (is_resource($file)) fclose($file);
            if ($created) @unlink($outputFile);
            throw $e;
        } finally {
            umask($mask);
            $this->config->setUserId($previousUser);
        }
    }

    private function selectUser(string $user): void {
        if ($user === '' || !$this->users->userExists($user)) throw new \RuntimeException('Select an existing Nextcloud user.');
        $this->config->setUserId($user);
        $this->idle();
    }

    private function activeQdrantGeneration(string $user): VectorGeneration {
        $generation = $this->generations->activeQdrantForUser($user);
        if ($generation === null || $this->generations->retrievalBackendForUser($user) !== 'qdrant_generation') {
            throw new \RuntimeException('Select an active verified Qdrant generation with no pending source changes.');
        }
        $target = $this->generations->target($user, $generation->id);
        if (!is_string($target) || preg_match('/^[a-f0-9]{64}$/D', $target) !== 1) throw new \RuntimeException('The active generation has no retained Qdrant target.');
        return $generation;
    }

    private function assertActiveQdrantGeneration(string $user, VectorGeneration $expected): void {
        $active = $this->activeQdrantGeneration($user);
        if ($active->toRow() !== $expected->toRow()) throw new \RuntimeException('The active Qdrant generation changed during the benchmark.');
    }

    private function assertQdrantSnapshot(string $user, VectorGeneration $generation, string $expectedTarget,
        string $expectedHash, string $revision, string $configurationHash): void {
        $this->idle();
        $this->assertActiveQdrantGeneration($user, $generation);
        $target = $this->generations->target($user, $generation->id);
        if (!is_string($target) || !hash_equals($expectedTarget, $target)
            || !hash_equals($configurationHash, $this->configurationHash($user))
            || $revision !== $this->config->get('search_revision')) throw new \RuntimeException('Generation or account configuration changed.');
        $hash = hash_init('sha256');
        foreach ($this->generationRows($user, $generation) as $row) hash_update($hash, $this->encode($row) . "\n");
        if (!hash_equals($expectedHash, hash_final($hash))) throw new \RuntimeException('The active SQL generation differs from the frozen snapshot.');
        $this->idle();
        $this->assertActiveQdrantGeneration($user, $generation);
        if (!hash_equals($configurationHash, $this->configurationHash($user))
            || $revision !== $this->config->get('search_revision')) throw new \RuntimeException('Generation or account configuration changed during fingerprint verification.');
    }

    private function generationRows(string $user, VectorGeneration $generation): \Generator {
        $cursor = 0;
        do {
            $page = $this->generationChunks->benchmarkRowsAfter($user, $generation, $cursor);
            foreach ($page as $row) {
                $id = (int)$row['id'];
                if ($id <= $cursor || $row['user_id'] !== $user || !hash_equals((string)$row['content_hash'], hash('sha256', (string)$row['content']))) {
                    throw new \RuntimeException('Invalid or stale Qdrant generation row in benchmark export.');
                }
                $vector = json_decode((string)$row['embedding'], true, 64, JSON_THROW_ON_ERROR);
                try { if (!is_array($vector)) throw new \InvalidArgumentException(); $generation->validateVector($vector); }
                catch (\Throwable) { throw new \RuntimeException('Generation contains an invalid embedding.'); }
                $pointRevision = (int)$row['vector_revision'];
                if ($pointRevision < 1) throw new \RuntimeException('Generation row has no delivered point revision.');
                $cursor = $id;
                yield ['id' => $id, 'document_id' => (int)$row['document_id'], 'user' => $user,
                    'path' => (string)$row['path'], 'name' => (string)$row['name'], 'source' => (string)$row['source'], 'file_id' => (int)$row['file_id'],
                    'content_sha256' => hash('sha256', (string)$row['content']), 'provenance_sha256' => hash('sha256', (string)$row['provenance']),
                    'vector' => array_map('floatval', $vector)];
            }
        } while ($page !== []);
    }

    private function generationQueryVector(VectorGeneration $generation, string $text): array {
        $guard = fn() => $this->assertActiveQdrantGeneration($generation->userId, $generation);
        $vectors = $this->generationEmbeddings->embed($generation, [$text], $guard, false, 30);
        $vector = $vectors[0] ?? null;
        if (!is_array($vector)) throw new \RuntimeException('Generation-pinned query embedding could not be generated.');
        $generation->validateVector($vector);
        return array_map('floatval', $vector);
    }

    private function idle(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('configvalue')->from('preferences')
            ->where($qb->expr()->eq('userid', $qb->createNamedParameter($this->config->userId())))
            ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(AppConfig::APP)))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('index_running')));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (($row['configvalue'] ?? '') === '1') throw new \RuntimeException('Stop indexing before snapshot export or recording.');
    }

    /** Configured identity is not evidence of the model that produced old rows. */
    private function identity(): array {
        $identity = ['endpoint_sha256' => hash('sha256', $this->config->ollamaUrl())];
        foreach (['embedding_model', 'embedding_model_fallback', 'chunk_size', 'chunk_overlap', 'index_config_hash',
            'chat_provider', 'chat_model', 'top_k', 'scope_path'] as $key) $identity[$key] = $this->config->get($key);
        return $identity;
    }

    private function assertSnapshot(string $user, array $identity, string $expectedHash, string $revision, string $configurationHash): void {
        $this->idle();
        if (!hash_equals($configurationHash, $this->configurationHash($user))) throw new \RuntimeException('Instance or user configuration changed.');
        if ($identity !== $this->identity() || $revision !== $this->config->get('search_revision')) throw new \RuntimeException('Retrieval configuration or index revision changed.');
        $hash = hash_init('sha256');
        foreach ($this->rows($user) as $row) hash_update($hash, $this->encode($row) . "\n");
        if (!hash_equals($expectedHash, hash_final($hash))) throw new \RuntimeException('Live SQL corpus differs from the frozen snapshot.');
        $this->idle();
        if (!hash_equals($configurationHash, $this->configurationHash($user))) throw new \RuntimeException('Configuration changed during fingerprint verification.');
        if ($identity !== $this->identity() || $revision !== $this->config->get('search_revision')) throw new \RuntimeException('Index changed during fingerprint verification.');
    }

    /** Bypass request-local IConfig caches; export only a hash, never secrets. */
    private function configurationHash(string $user): string {
        $hash = hash_init('sha256');
        foreach (['appconfig', 'preferences'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('configkey', 'configvalue')->from($table)
                ->where($qb->expr()->eq('appid', $qb->createNamedParameter(AppConfig::APP)))
                ->orderBy('configkey', 'ASC');
            if ($table === 'preferences') $qb->andWhere($qb->expr()->eq('userid', $qb->createNamedParameter($user)));
            $result = $qb->executeQuery();
            try {
                while ($row = $result->fetch()) hash_update($hash, $this->encode([$table, $row['configkey'], $row['configvalue']]) . "\n");
            } finally { $result->closeCursor(); }
        }
        return hash_final($hash);
    }

    private function rows(string $user): \Generator {
        $cursor = 0;
        do {
            $page = $this->chunks->benchmarkRowsAfter($user, $cursor);
            foreach ($page as $row) {
                $id = (int)$row['id'];
                if ($id <= $cursor || $row['user_id'] !== $user) throw new \RuntimeException('Invalid keyset cursor or tenant in export.');
                $cursor = $id;
                $vector = json_decode((string)$row['embedding'], true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($vector) || !array_is_list($vector) || $vector === [] || count($vector) > 16384) throw new \RuntimeException('Index contains an invalid embedding.');
                foreach ($vector as $value) if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) throw new \RuntimeException('Index embedding is not finite numeric data.');
                yield ['id' => $id, 'document_id' => (int)$row['document_id'], 'user' => $user,
                    'path' => (string)$row['path'], 'name' => (string)$row['name'], 'source' => (string)$row['source'], 'file_id' => (int)$row['file_id'],
                    'content_sha256' => hash('sha256', (string)$row['content']), 'provenance_sha256' => hash('sha256', (string)$row['provenance']),
                    'vector' => array_map('floatval', $vector)];
            }
        } while ($page !== []);
    }

    private function queryVector(string $text, string $user): array {
        [$vectors, $error] = $this->embeddings->embedQuery([$text], $user);
        if ($error !== null || !is_array($vectors) || !is_array($vectors[0] ?? null) || !array_is_list($vectors[0]) || $vectors[0] === [] || count($vectors[0]) > 16384) throw new \RuntimeException('Query embedding could not be generated.');
        $norm = 0.0;
        foreach ($vectors[0] as $value) {
            if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) throw new \RuntimeException('Query embedding is invalid.');
            $norm += $value * $value;
        }
        if ($norm <= 0 || !is_finite($norm)) throw new \RuntimeException('Query embedding has invalid magnitude.');
        return array_map('floatval', $vectors[0]);
    }

    private function encode(array $data): string { return json_encode($data, self::JSON); }
    private function openNew(string $path) {
        $file = fopen($path, 'xb');
        if ($file === false) throw new \RuntimeException('Output must be a new writable file.');
        return $file;
    }
    private function writeNew(string $path, string $bytes): void {
        $file = $this->openNew($path);
        try { $this->write($file, $bytes); } finally { fclose($file); }
    }
    private function write($file, string $bytes): void {
        while ($bytes !== '') {
            $written = fwrite($file, $bytes);
            if ($written === false || $written === 0) throw new \RuntimeException('Benchmark output write failed.');
            $bytes = substr($bytes, $written);
        }
    }
}
