<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Db\VectorOutboxRepository;
use Psr\Log\LoggerInterface;

/** Consumes terminal generation deletion only. Chunk operations stay unclaimed. */
class VectorErasureWorker {
    public function __construct(private VectorOutboxRepository $outbox, private VectorGenerationRepository $generations,
        private QdrantVectorStore $store, private LoggerInterface $logger) {}

    public function run(int $limit = 5): array {
        $limit = max(1, min(20, $limit));
        $stats = ['enabled' => $this->outbox->workerEnabled(), 'claimed' => 0, 'deferred' => 0, 'delivered' => 0, 'failed' => 0, 'lost_leases' => 0];
        if (!$stats['enabled']) return $stats;
        $started = hrtime(true);
        while ($stats['claimed'] < $limit && (hrtime(true) - $started) / 1_000_000_000 < 40 && $this->outbox->workerEnabled()) {
            $row = $this->outbox->claim(time(), 120, 'delete_generation');
            if ($row === null) break;
            $stats['claimed']++;
            $id = (int)$row['id']; $token = (string)$row['lease_token'];
            try {
                $generation = $this->decode($row);
                $now = time();
                $readyAt = ($row['erasure_ready_at'] ?? null) === null ? null : (int)$row['erasure_ready_at'];
                if ($readyAt !== null && $readyAt < 1) throw new VectorStoreException('invalid_event');
                if ($readyAt === null || $now < $readyAt) {
                    if ($this->outbox->deferErasure($id, $token, $now, $readyAt)) $stats['deferred']++;
                    else $stats['lost_leases']++;
                    continue;
                }
                $this->assertRetired($generation);
                $guard = function () use ($id, $token, $generation): void {
                    if (!$this->outbox->workerEnabled()) throw new VectorStoreException('sync_disabled');
                    if (!$this->outbox->ownsLease($id, $token, time())) throw new VectorStoreException('stale_lease');
                    $this->assertRetired($generation);
                };
                $this->store->forTarget($row['target_hash'], $guard)->deleteGeneration($generation);
                if ($this->outbox->complete($id, $token, time())) $stats['delivered']++;
                else $stats['lost_leases']++;
            } catch (\Throwable $e) {
                $code = $e instanceof VectorStoreException && preg_match('/^[a-z0-9_]{1,48}$/D', $e->reason) ? $e->reason : 'erasure_failed';
                $permanent = in_array($code, ['invalid_event', 'unsafe_erasure'], true);
                if ($this->outbox->failed($id, $token, $code, time(), $permanent)) $stats['failed']++;
                else $stats['lost_leases']++;
                try { $this->logger->warning('eva_ai: vector generation erasure incomplete', ['event_id' => $id, 'reason' => $code]); } catch (\Throwable) {}
            }
        }
        return $stats;
    }

    private function decode(array $row): VectorGeneration {
        try {
            if (($row['operation'] ?? '') !== 'delete_generation' || ($row['document_id'] ?? null) !== null || ($row['chunk_id'] ?? null) !== null
                || !is_string($row['generation_json'] ?? null) || strlen($row['generation_json']) > 8192
                || !is_string($row['target_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $row['target_hash']) !== 1) throw new \RuntimeException();
            $data = json_decode($row['generation_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \RuntimeException();
            $generation = VectorGeneration::fromRow($data);
            if ($generation->id !== $row['generation_id'] || $generation->userId !== $row['user_id']) throw new \RuntimeException();
            return $generation;
        } catch (\Throwable) { throw new VectorStoreException('invalid_event'); }
    }

    private function assertRetired(VectorGeneration $generation): void {
        // Deleted accounts need not exist anymore. The immutable event retains
        // exactly the generation identity required for remote erasure.
        $status = $this->generations->status($generation->userId, $generation->id);
        if ($status !== null && $status !== 'retired') throw new VectorStoreException('unsafe_erasure');
    }
}
