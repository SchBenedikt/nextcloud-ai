<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\GenerationBuildRepository;
use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Service\AppConfig;
use Psr\Log\LoggerInterface;

/** Starts the internal SQL generation build after a user has enrolled in indexing. */
final class DefaultSqlGenerationProvisioner {
    public function __construct(
        private AppConfig $config,
        private VectorGenerationRepository $generations,
        private GenerationBuildRepository $builds,
        private LoggerInterface $logger,
    ) {}

    public function ensure(string $userId, string $model, int $dimension): void {
        if ($userId === '' || $model === '' || $dimension < 1 || !$this->isEnrolled($userId)) return;

        $endpointHash = hash('sha256', $this->config->ollamaUrl());
        $chunkSize = $this->config->getInt('chunk_size', 900);
        $chunkOverlap = $this->config->getInt('chunk_overlap', 120);
        try {
            $generation = $this->generations->findBuildingSql($userId, $model, $endpointHash, $dimension, $chunkSize, $chunkOverlap);
            if ($generation === null) {
                $generation = new VectorGeneration(bin2hex(random_bytes(16)), $userId, 'sql', 'ollama', null,
                    $model, $endpointHash, $dimension, $chunkSize, $chunkOverlap);
                $this->generations->create($generation);
            }
            $target = $this->generations->target($userId, $generation->id);
            if ($target !== null) $this->builds->start($userId, $generation->id, $target);
        } catch (\Throwable $e) {
            // SQL generation indexing is an optimization; it must never fail the legacy index pass.
            $this->logger->warning('eva_ai: could not schedule the default SQL generation build', [
                'exception' => $e->getMessage(),
            ]);
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
