<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\{GenerationChunkRepository, VectorGenerationRepository, VectorOutboxRepository};
use Psr\Log\LoggerInterface;

/** Bounded, explicit point transport. It never prepares or recreates collections. */
class VectorPointWorker {
    public function __construct(private VectorOutboxRepository $outbox, private VectorGenerationRepository $generations,
        private GenerationChunkRepository $chunks, private VectorSourceAccess $access, private QdrantVectorStore $store,
        private LoggerInterface $logger) {}

    public function run(int $limit = 100): array {
        $limit = max(1, min(100, $limit));
        $stats = ['enabled' => $this->outbox->pointsEnabled(), 'claimed' => 0, 'delivered' => 0, 'superseded' => 0, 'failed' => 0, 'lost_leases' => 0];
        if (!$stats['enabled']) return $stats;
        $started = hrtime(true);
        $checkedTargets = [];
        while ($stats['claimed'] < $limit && (hrtime(true) - $started) / 1_000_000_000 < 30 && $this->outbox->pointsEnabled()) {
            $event = $this->outbox->claimPoint(time(), 120);
            if ($event === null) break;
            $stats['claimed']++;
            $id = (int)$event['id']; $token = (string)$event['lease_token'];
            try {
                $generation = $this->decode($event);
                $guard = function () use ($event, $generation, $id, $token): ?array {
                    if (!$this->outbox->pointsEnabled()) throw new VectorStoreException('sync_disabled');
                    if (!$this->outbox->ownsLease($id, $token, time())) throw new VectorStoreException('stale_lease');
                    $state = $this->generations->deliveryState($generation, $event['target_hash']);
                    if ($state === null || $state === 'retired') {
                        if ($this->outbox->hasGenerationErasure($generation->userId, $generation->id)) throw new VectorStoreException('generation_erasure');
                        throw new VectorStoreException('generation_unavailable');
                    }
                    if ($state === 'failed' && $event['operation'] === 'upsert') throw new VectorStoreException('generation_failed');
                    if ($this->generations->ownerStatus($generation->userId) !== 'open') throw new VectorStoreException('source_unavailable');
                    if ($event['operation'] === 'upsert') {
                        if (!$this->outbox->pointTargetIsCurrent($event['target_hash'])) throw new VectorStoreException('target_inactive');
                        return $this->currentPoint($generation, $event);
                    }
                    $this->access->assertOwner($generation->userId);
                    return null;
                };
                $point = $guard();
                $target = $this->store->forTarget($event['target_hash'], $guard);
                if (!isset($checkedTargets[$event['target_hash']])) {
                    $health = $target->health();
                    if (($health['available'] ?? false) !== true) throw new VectorStoreException($health['code'] ?? 'unsupported_version');
                    $checkedTargets[$event['target_hash']] = true;
                }
                if ($point !== null) $target->upsert($generation, [$point], $id);
                else $target->tombstoneChunks($generation, [(int)$event['chunk_id']], $id);
                if ($this->outbox->completePoint($event, time())) $stats['delivered']++;
                else $stats['lost_leases']++;
            } catch (\Throwable $e) {
                $code = $e instanceof VectorStoreException && preg_match('/^[a-z0-9_]{1,48}$/D', $e->reason) ? $e->reason : 'point_delivery_failed';
                if ($code === 'stale_lease') { $stats['lost_leases']++; continue; }
                if (in_array($code, ['stale_point', 'generation_erasure', 'generation_failed'], true)) {
                    if ($this->outbox->supersedePoint($id, $token, $code, time())) $stats['superseded']++;
                    else $stats['lost_leases']++;
                    continue;
                }
                $permanent = in_array($code, ['invalid_event', 'generation_identity_mismatch', 'invalid_revision'], true);
                if ($this->outbox->failed($id, $token, $code, time(), $permanent)) $stats['failed']++;
                else $stats['lost_leases']++;
                try { $this->logger->warning('eva_ai: vector point delivery incomplete', ['event_id' => $id, 'reason' => $code]); } catch (\Throwable) {}
            }
        }
        return $stats;
    }

    private function currentPoint(VectorGeneration $generation, array $event): array {
        $point = $this->chunks->pointForDelivery($generation, (int)$event['chunk_id'], (int)$event['id']);
        if ($point === null) throw new VectorStoreException('stale_point');
        if ($point['document_id'] !== (int)$event['document_id']) throw new VectorStoreException('invalid_event');
        $this->access->assertAccessible($generation->userId, $point);
        return $point;
    }

    private function decode(array $event): VectorGeneration {
        try {
            if (!in_array($event['operation'] ?? '', ['upsert', 'delete_chunk'], true)
                || (int)($event['id'] ?? 0) < 1 || (int)$event['id'] > 9007199254740991
                || (int)($event['document_id'] ?? 0) < 1 || (int)($event['chunk_id'] ?? 0) < 1
                || !is_string($event['generation_json'] ?? null) || strlen($event['generation_json']) > 8192
                || !is_string($event['target_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $event['target_hash']) !== 1) throw new \RuntimeException();
            $data = json_decode($event['generation_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \RuntimeException();
            $generation = VectorGeneration::fromRow($data);
            if ($generation->id !== $event['generation_id'] || $generation->userId !== $event['user_id']) throw new \RuntimeException();
            return $generation;
        } catch (\Throwable) { throw new VectorStoreException('invalid_event'); }
    }
}
