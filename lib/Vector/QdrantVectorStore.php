<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Optional dense index. It is not selected by Searcher or indexing yet. */
class QdrantVectorStore implements VectorStore {
    private ?\Closure $beforeTransfer = null;
    // Qdrant numeric range comparisons use doubles; retain exact revision ordering.
    private const MAX_REVISION = 9007199254740991;
    private const INDEXES = ['user_id' => 'keyword', 'generation_id' => 'keyword', 'document_id' => 'integer', 'source' => 'keyword', 'scope_paths' => 'keyword', 'revision' => 'integer', 'deleted' => 'bool'];
    public function __construct(private QdrantConnection $connection, private ?string $targetHash = null) {}

    public function forTarget(string $targetHash, ?\Closure $beforeTransfer = null): self {
        if (preg_match('/^[a-f0-9]{64}$/D', $targetHash) !== 1) throw new VectorStoreException('invalid_target');
        $store = new self($this->connection, $targetHash);
        $store->beforeTransfer = $beforeTransfer;
        return $store;
    }

    public function health(): array {
        try {
            $data = $this->request('get', '/', null, false, 3);
            $version = $data['version'] ?? '';
            if (!is_string($version) || !preg_match('/^1\.\d+\.\d+(?:[-+].*)?$/D', $version) || version_compare($version, '1.16.0', '<')) {
                return ['available' => false, 'code' => 'unsupported_version'];
            }
            return ['available' => true, 'code' => 'api_reachable'];
        } catch (VectorStoreException $e) { return ['available' => false, 'code' => $e->reason]; }
        catch (\Throwable) { return ['available' => false, 'code' => 'unavailable']; }
    }

    /** Explicit preparation, not a constructor or installation side effect. */
    public function prepare(VectorGeneration $generation): void {
        $health = $this->health();
        if (!$health['available']) throw new VectorStoreException($health['code']);
        $path = $this->collection($generation);
        $data = $this->request('get', $path, null, true);
        if ($data === null) {
            try { $this->ok($this->request('put', $path, ['vectors' => ['dense' => ['size' => $generation->dimension, 'distance' => 'Cosine']]])); }
            catch (VectorStoreException $e) {
                // Another explicit preparer may have won collection creation.
                if ($e->reason !== 'http_409') throw $e;
            }
            $data = $this->request('get', $path);
        }
        $this->ok($data);
        $dense = $data['result']['config']['params']['vectors']['dense'] ?? null;
        if (!is_array($dense) || ($dense['size'] ?? null) !== $generation->dimension || ($dense['distance'] ?? '') !== 'Cosine') {
            throw new VectorStoreException('collection_identity_mismatch');
        }
        $schema = $data['result']['payload_schema'] ?? [];
        if (!is_array($schema)) throw new VectorStoreException('invalid_payload_schema');
        foreach (self::INDEXES as $field => $type) {
            if (isset($schema[$field])) {
                if (($schema[$field]['data_type'] ?? '') !== $type) throw new VectorStoreException('payload_index_mismatch');
                continue;
            }
            $this->completed($this->request('put', $path . '/index?wait=true&ordering=strong', ['field_name' => $field, 'field_schema' => $type]));
        }
    }

    public function upsert(VectorGeneration $generation, array $points, int $revision): void {
        $this->validateRevision($revision);
        if (!array_is_list($points) || count($points) > 200) throw new VectorStoreException('invalid_batch');
        if ($points === []) return;
        $prepared = []; $ids = []; $elements = 0;
        foreach ($points as $point) {
            if (!is_array($point) || !is_int($point['id'] ?? null) || $point['id'] < 1 || isset($ids[$point['id']])
                || !is_int($point['document_id'] ?? null) || $point['document_id'] < 1
                || !is_int($point['file_id'] ?? null) || !is_int($point['chunk_index'] ?? null) || $point['chunk_index'] < 0
                || !in_array($point['source'] ?? null, ['files', 'mail', 'talk'], true)
                || !is_string($point['path'] ?? null) || $point['path'] === ''
                || !is_string($point['content_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $point['content_hash']) !== 1
                || !is_array($point['vector'] ?? null)) throw new VectorStoreException('invalid_point');
            new SearchFilters($point['path']); // Canonical scope syntax, not arbitrary filter JSON.
            $generation->validateVector($point['vector']);
            $elements += count($point['vector']);
            if ($elements > 250000) throw new VectorStoreException('batch_too_large');
            $ids[$point['id']] = true;
            $prepared[] = ['id' => $point['id'], 'vector' => ['dense' => array_map('floatval', $point['vector'])],
                'payload' => ['user_id' => $this->tenant($generation), 'generation_id' => $generation->id, 'revision' => $revision, 'deleted' => false,
                    'document_id' => $point['document_id'], 'file_id' => $point['file_id'], 'chunk_index' => $point['chunk_index'],
                    'source' => $point['source'], 'content_hash' => $point['content_hash'], 'scope_paths' => $this->scopes($point['path'])]];
        }
        // Validate the entire batch before the first network request.
        $this->writeRevision($generation, $prepared, $revision, false);
    }

    public function tombstoneChunks(VectorGeneration $generation, array $chunkIds, int $revision): void {
        $this->validateRevision($revision);
        if (!array_is_list($chunkIds) || count($chunkIds) > 200) throw new VectorStoreException('invalid_batch');
        if ($chunkIds === []) return;
        if (count($chunkIds) * $generation->dimension > 250000) throw new VectorStoreException('batch_too_large');
        // Overwrite the old embedding and metadata; a tombstone must reveal no file content.
        $dummy = array_fill(0, $generation->dimension, 0.0); $dummy[0] = 1.0;
        $points = []; $seen = [];
        foreach ($chunkIds as $id) {
            if (!is_int($id) || $id < 1 || isset($seen[$id])) throw new VectorStoreException('invalid_point');
            $seen[$id] = true;
            $points[] = ['id' => $id, 'vector' => ['dense' => $dummy], 'payload' => [
                'user_id' => $this->tenant($generation), 'generation_id' => $generation->id,
                'revision' => $revision, 'deleted' => true]];
        }
        // Materialize even missing points: physical deletion would allow a late insert.
        $this->writeRevision($generation, $points, $revision, true);
    }

    private function writeRevision(VectorGeneration $generation, array $points, int $revision, bool $deleted): void {
        $must = [['key' => 'user_id', 'match' => ['value' => $this->tenant($generation)]],
            ['key' => 'generation_id', 'match' => ['value' => $generation->id]],
            ['key' => 'revision', 'range' => ['lte' => $revision]]];
        // Tombstones are terminal for a stable ID, even for a higher-revision upsert.
        if (!$deleted) $must[] = ['key' => 'deleted', 'match' => ['value' => false]];
        $this->completed($this->request('put', $this->collection($generation) . '/points?wait=true&ordering=strong',
            ['points' => $points, 'update_filter' => ['must' => $must]]));
        // "completed" confirms execution, not that every conditional update matched.
        // A later generation verifier must compare IDs, revisions and deletion state.
    }

    private function validateRevision(int $revision): void {
        if ($revision < 1 || $revision > self::MAX_REVISION) throw new VectorStoreException('invalid_revision');
    }

    /** A missing collection already satisfies terminal generation erasure. */
    public function deleteGeneration(VectorGeneration $generation): void {
        $data = $this->request('delete', $this->collection($generation) . '?timeout=10', null, true, 10);
        if ($data !== null) {
            $this->ok($data);
            if (($data['result'] ?? null) !== true) throw new VectorStoreException('operation_not_completed');
        }
    }

    public function search(VectorGeneration $generation, array $queryVector, int $topK, SearchFilters $filters): array {
        $generation->validateVector($queryVector);
        if ($topK < 1 || $topK > 100) throw new VectorStoreException('invalid_limit');
        $data = $this->request('post', $this->collection($generation) . '/points/query?timeout=3',
            ['query' => array_map('floatval', $queryVector), 'using' => 'dense', 'limit' => $topK,
                'filter' => $this->filter($generation, $filters), 'with_vector' => false,
                'with_payload' => ['user_id', 'generation_id', 'document_id', 'source', 'scope_paths', 'revision', 'deleted']], false, 5);
        $this->ok($data);
        $points = $data['result']['points'] ?? null;
        if (!is_array($points) || !array_is_list($points) || count($points) > $topK) throw new VectorStoreException('invalid_search_response');
        $results = []; $seen = [];
        foreach ($points as $point) {
            $id = $point['id'] ?? null;
            $payload = $point['payload'] ?? null;
            $score = $point['score'] ?? null;
            if (!is_int($id) || $id < 1 || isset($seen[$id]) || (!is_int($score) && !is_float($score)) || !is_finite((float)$score)
                || !is_array($payload) || ($payload['user_id'] ?? '') !== $this->tenant($generation) || ($payload['generation_id'] ?? '') !== $generation->id
                || ($payload['deleted'] ?? null) !== false
                || !is_int($payload['revision'] ?? null) || $payload['revision'] < 1 || $payload['revision'] > self::MAX_REVISION
                || !is_int($payload['document_id'] ?? null) || $payload['document_id'] < 1
                || !in_array($payload['source'] ?? null, ['files', 'mail', 'talk'], true)
                || ($filters->source !== null && $payload['source'] !== $filters->source)
                || ($filters->documentIds !== [] && !in_array($payload['document_id'], $filters->documentIds, true))
                || ($filters->scopePath !== null && $filters->scopePath !== '' && (!is_array($payload['scope_paths'] ?? null) || !in_array(hash('sha256', $filters->scopePath), $payload['scope_paths'], true)))) {
                // Do not quietly drop a cross-tenant response and conceal a violated invariant.
                throw new VectorStoreException('search_isolation_violation');
            }
            $seen[$id] = true;
            $results[] = ['chunkId' => $id, 'revision' => $payload['revision'], 'score' => (float)$score];
        }
        return $results;
    }

    private function filter(VectorGeneration $generation, SearchFilters $filters): array {
        $must = [['key' => 'user_id', 'match' => ['value' => $this->tenant($generation)]],
            ['key' => 'generation_id', 'match' => ['value' => $generation->id]],
            ['key' => 'deleted', 'match' => ['value' => false]]];
        if ($filters->source !== null) $must[] = ['key' => 'source', 'match' => ['value' => $filters->source]];
        if ($filters->documentIds !== []) $must[] = ['key' => 'document_id', 'match' => ['any' => $filters->documentIds]];
        if ($filters->scopePath !== null && $filters->scopePath !== '') $must[] = ['key' => 'scope_paths', 'match' => ['value' => hash('sha256', $filters->scopePath)]];
        return ['must' => $must];
    }

    private function scopes(string $path): array {
        $parts = explode('/', $path); $prefix = ''; $tokens = [];
        if (count($parts) > 128) throw new VectorStoreException('scope_too_deep');
        foreach ($parts as $part) { $prefix .= ($prefix === '' ? '' : '/') . $part; $tokens[] = hash('sha256', $prefix); }
        return $tokens;
    }
    private function request(string $method, string $path, ?array $payload = null, bool $allowMissing = false, int $timeout = 30): ?array {
        return $this->connection->request($method, $path, $payload, $allowMissing, $timeout, $this->targetHash, $this->beforeTransfer);
    }

    private function collection(VectorGeneration $generation): string { return '/collections/' . $generation->collection(); }
    private function tenant(VectorGeneration $generation): string { return hash('sha256', $generation->userId); }
    private function ok(?array $data): void { if (($data['status'] ?? null) !== 'ok') throw new VectorStoreException('invalid_operation_response'); }
    private function completed(?array $data): void {
        $this->ok($data);
        if (($data['result']['status'] ?? '') !== 'completed') throw new VectorStoreException('operation_not_completed');
    }
}
