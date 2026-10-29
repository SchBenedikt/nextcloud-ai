<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\VectorOutboxRepository;

/** Offline, secret-free snapshot shared by both administration surfaces. */
class VectorStorageStatus {
    public function __construct(private QdrantConnection $connection, private VectorOutboxRepository $outbox,
        private \OCA\EvaAi\Db\GenerationChunkRepository $chunks,
        private \OCA\EvaAi\Db\VectorGenerationRepository $generations) {}

    public function snapshot(): array {
        try {
            $connection = $this->connection->status(); // No DNS, health probe or decryption.
            $workerEnabled = $this->outbox->workerEnabled();
            $queue = array_fill_keys(['pending', 'processing', 'dead'], 0);
            foreach ($this->outbox->stats(true) as $row) {
                if (array_key_exists($row['state'], $queue)) $queue[$row['state']] = max(0, (int)$row['events']);
            }
            $activeSqlGenerations=$this->generations->activeSqlCount();
            return ['available' => true, 'retrieval_backend' => $activeSqlGenerations > 0 ? 'sql_generation' : 'sql_legacy',
                'active_sql_accounts' => $activeSqlGenerations,
                'connection' => $connection, 'erasure_worker_enabled' => $workerEnabled,
                'chunk_sync_available' => true, 'points_requested' => $this->outbox->pointDeliveryRequested(),
                'point_worker_enabled' => $this->outbox->pointsEnabled(), 'staged_chunks' => $this->chunks->countStaged(), 'queue' => $queue, 'observed_at' => time()];
        } catch (\Throwable) {
            // A missing migration/database failure is not an empty, healthy queue.
            return ['available' => false];
        }
    }
}
