<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\GenerationBuildRepository;
use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Service\{AppConfig,EmbeddingCache,EmbeddingProvider};
use Psr\Log\LoggerInterface;

/** Starts the internal SQL generation build after a user has enrolled in indexing. */
final class DefaultSqlGenerationProvisioner {
    public function __construct(
        private AppConfig $config,
        private VectorGenerationRepository $generations,
        private GenerationBuildRepository $builds,
        private EmbeddingCache $cache,
        private LoggerInterface $logger,
    ) {}

    /** @param list<array{text:string,vector:list<float|int>}> $provenVectors */
    public function ensure(string $userId, string $model, int $dimension, array $provenVectors = []): void {
        if ($userId === '' || $model === '' || $dimension < 1 || !$this->isEnrolled($userId)) return;

        $endpointHash = hash('sha256', $this->config->ollamaUrl());
        $chunkSize = $this->config->getInt('chunk_size', 900);
        $chunkOverlap = $this->config->getInt('chunk_overlap', 120);
        try {
            $retrievalBackend = $this->generations->retrievalBackendForUser($userId);
            // A deliberately selected Qdrant primary is never replaced or
            // shadowed by automatic SQL provisioning.
            if ($retrievalBackend === 'qdrant_generation') return;

            // Normal indexing already journals changed sources for the active
            // generation. Reuse it when immutable embedding settings still
            // match; otherwise this is a genuine model/configuration rebuild.
            $activeId = $this->generations->activeGenerationId($userId);
            $active = $activeId === null ? null : $this->generations->find($userId, $activeId);
            if ($this->matches($active, $model, $endpointHash, $dimension, $chunkSize, $chunkOverlap)
                && $retrievalBackend === 'sql_generation') {
                $this->cacheProvenVectors($active, $provenVectors);
                return;
            }

            $generation = $this->generations->findBuildingSql($userId, $model, $endpointHash, $dimension, $chunkSize, $chunkOverlap);
            if ($generation === null) {
                $generation = new VectorGeneration(bin2hex(random_bytes(16)), $userId, 'sql', 'ollama', null,
                    $model, $endpointHash, $dimension, $chunkSize, $chunkOverlap);
                $this->generations->create($generation);
            }
            $this->cacheProvenVectors($generation, $provenVectors);
            $target = $this->generations->target($userId, $generation->id);
            if ($target !== null) $this->builds->start($userId, $generation->id, $target);
        } catch (\Throwable $e) {
            // SQL generation indexing is an optimization; it must never fail the legacy index pass.
            $this->logger->warning('eva_ai: could not schedule the default SQL generation build', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function matches(?VectorGeneration $generation, string $model, string $endpointHash,
        int $dimension, int $chunkSize, int $chunkOverlap): bool {
        return $generation !== null && $generation->backend === 'sql' && $generation->embeddingProvider === 'ollama'
            && $generation->embeddingModel === $model && hash_equals($generation->endpointHash, $endpointHash)
            && $generation->dimension === $dimension && $generation->chunkSize === $chunkSize
            && $generation->chunkOverlap === $chunkOverlap && $this->generations->status($generation->userId, $generation->id) === 'active';
    }

    /** Exact text and proven vectors are cached only under the full generation identity. */
    private function cacheProvenVectors(VectorGeneration $generation, array $entries): void {
        if ($entries === []) return;
        $texts = []; $vectors = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['text'] ?? null) || !is_array($entry['vector'] ?? null)) continue;
            try { $generation->validateVector($entry['vector']); } catch (\Throwable) { continue; }
            $texts[] = $entry['text']; $vectors[] = $entry['vector'];
        }
        if ($texts !== []) $this->cache->putForGeneration($generation, $texts, $vectors);
    }

    /**
     * Bootstrap existing enrolled indexes even when their files have not changed.
     * The unique public probe avoids sending existing document content just to
     * discover the current embedding identity. A cooldown keeps unavailable
     * providers from adding a request to every cron tick.
     */
    public function bootstrap(string $userId, EmbeddingProvider $provider): void {
        if ($userId === '' || !$this->isEnrolled($userId)
            || $this->generations->hasSqlGeneration($userId)
            || $this->generations->retrievalBackendForUser($userId) === 'qdrant_generation') return;

        $previous = $this->config->userId();
        try {
            $this->config->setUserId($userId);
            if ((int)$this->config->get('sql_generation_probe_after') > time()) return;
            $this->config->set('sql_generation_probe_after', (string)(time() + 21600));
            [$vectors, $error] = $provider->embedBatch(['EVA local SQL generation compatibility probe ' . bin2hex(random_bytes(12))]);
            $model = $provider->lastEmbeddingModel();
            $vector = $vectors[0] ?? null;
            if ($error === null && $model !== null && is_array($vector) && $vector !== []) {
                $this->ensure($userId, $model, count($vector));
            }
        } catch (\Throwable $e) {
            $this->logger->debug('eva_ai: default SQL generation bootstrap deferred', ['exception' => $e::class]);
        } finally {
            $this->config->setUserId($previous);
        }
    }

    private function isEnrolled(string $userId): bool {
        // Unlike the backward-compatible build guard, bootstrap requires explicit opt-in.
        $previous = $this->config->userId();
        try {
            $this->config->setUserId($userId);
            return $this->config->get('index_enrolled') === '1';
        } finally {
            $this->config->setUserId($previous);
        }
    }
}
