<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorGeneration;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Durable intent only; never holds a database transaction during remote I/O. */
class VectorOutboxRepository {
    private const MAX_ATTEMPTS = 12;
    public function __construct(private IDBConnection $db) {}

    public function transaction(callable $mutation): mixed {
        $this->db->beginTransaction();
        try { $result = $mutation(); $this->db->commit(); return $result; }
        catch (\Throwable $e) {
            try { if ($this->db->inTransaction()) $this->db->rollBack(); } catch (\Throwable) {}
            throw $e;
        }
    }

    /** Must share the transaction and connection of the canonical SQL mutation. */
    public function enqueue(VectorGeneration $generation, string $targetHash, string $operation, ?int $documentId = null, ?int $chunkId = null): int {
        if (!$this->db->inTransaction()) throw new \LogicException('Vector intent requires the canonical mutation transaction.');
        if (preg_match('/^[a-f0-9]{64}$/D', $targetHash) !== 1 || !in_array($operation, ['upsert', 'delete_chunk', 'delete_generation'], true)
            || ($operation === 'delete_generation' && ($documentId !== null || $chunkId !== null))
            || ($operation !== 'delete_generation' && ($documentId === null || $documentId < 1 || $chunkId === null || $chunkId < 1))) throw new \InvalidArgumentException('Invalid vector synchronization intent.');
        $json = json_encode($generation->toRow(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 8192) throw new \InvalidArgumentException('Generation metadata is oversized.');
        $now = time();
        $row = ['user_id' => $generation->userId, 'generation_id' => $generation->id, 'target_hash' => $targetHash,
            'operation' => $operation, 'state' => 'pending', 'generation_json' => $json,
            'document_id' => $documentId, 'chunk_id' => $chunkId, 'attempts' => 0, 'created_at' => $now, 'available_at' => $now];
        $qb = $this->db->getQueryBuilder(); $qb->insert('eva_ai_vec_outbox');
        foreach ($row as $key => $value) $qb->setValue($key, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : ($value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
        $id = $qb->getLastInsertId();
        if ($id < 1) throw new \RuntimeException('Vector journal did not allocate a revision.');
        return $id;
    }

    /** Bounded CAS claim. A stale worker cannot acknowledge a successor's lease. */
    public function claim(int $now, int $leaseSeconds = 120, string|array|null $operation = null): ?array {
        if ($leaseSeconds < 60 || $leaseSeconds > 600) throw new \InvalidArgumentException('Invalid vector lease interval.');
        $operations = $operation === null ? null : (is_string($operation) ? [$operation] : $operation);
        if ($operations !== null) {
            if (!array_is_list($operations) || $operations === [] || count($operations) > 3) throw new \InvalidArgumentException('Invalid claim operation.');
            foreach ($operations as $op) if (!is_string($op) || !in_array($op, ['upsert', 'delete_chunk', 'delete_generation'], true)) throw new \InvalidArgumentException('Invalid claim operation.');
        }
        for ($retry = 0; $retry < 8; $retry++) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')->from('eva_ai_vec_outbox')->where($qb->expr()->orX(
                $qb->expr()->andX($qb->expr()->eq('state', $qb->createNamedParameter('pending')), $qb->expr()->lte('available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))),
                $qb->expr()->andX($qb->expr()->eq('state', $qb->createNamedParameter('processing')), $qb->expr()->lte('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ))->orderBy('id', 'ASC')->setMaxResults(1);
            if ($operations !== null) $qb->andWhere($qb->expr()->in('operation', $qb->createNamedParameter($operations, IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            try { $row = $result->fetch(); } finally { $result->closeCursor(); }
            if (!$row) return null;
            $token = bin2hex(random_bytes(16));
            $dead = (int)$row['attempts'] >= self::MAX_ATTEMPTS;
            $update = $this->db->getQueryBuilder();
            $update->update('eva_ai_vec_outbox')->set('state', $update->createNamedParameter($dead ? 'dead' : 'processing'))
                ->set('lease_token', $update->createNamedParameter($dead ? null : $token, $dead ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
                ->set('lease_until', $update->createNamedParameter($dead ? null : $now + $leaseSeconds, $dead ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
                ->set('attempts', $update->createNamedParameter((int)$row['attempts'] + ($dead ? 0 : 1), IQueryBuilder::PARAM_INT))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($update->expr()->eq('state', $update->createNamedParameter($row['state'])))
                ->andWhere($update->expr()->eq('attempts', $update->createNamedParameter((int)$row['attempts'], IQueryBuilder::PARAM_INT)));
            if ($row['state'] === 'processing') {
                $update->andWhere($update->expr()->eq('lease_token', $update->createNamedParameter($row['lease_token'])))
                    ->andWhere($update->expr()->lte('lease_until', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
            } else $update->andWhere($update->expr()->lte('available_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
            if ($dead) $update->set('last_error', $update->createNamedParameter('lease_exhausted'));
            if ($update->executeStatement() !== 1 || $dead) continue;
            $row['state'] = 'processing'; $row['attempts'] = (int)$row['attempts'] + 1;
            $row['lease_token'] = $token; $row['lease_until'] = $now + $leaseSeconds;
            return $row;
        }
        return null;
    }

    public function claimPoint(int $now, int $leaseSeconds = 120): ?array {
        return $this->claim($now, $leaseSeconds, ['upsert', 'delete_chunk']);
    }

    /** Single-event CAS; never cancel a generation erasure while discarding stale points. */
    public function supersedePoint(int $id, string $token, string $reason, int $now): bool {
        if (preg_match('/^[a-z0-9_]{1,48}$/D', $reason) !== 1) throw new \InvalidArgumentException('Invalid supersession reason.');
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_outbox')->set('state', $qb->createNamedParameter('superseded'))
            ->set('last_error', $qb->createNamedParameter($reason))
            ->set('lease_token', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('lease_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('processing')))
            ->andWhere($qb->expr()->in('operation', $qb->createNamedParameter(['upsert', 'delete_chunk'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)))
            ->andWhere($qb->expr()->gt('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    public function hasGenerationErasure(string $userId, string $generationId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('eva_ai_vec_outbox')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('generation_id', $qb->createNamedParameter($generationId)))
            ->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('delete_generation')))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['pending', 'processing', 'dead', 'delivered'], IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1);
        $result = $qb->executeQuery();
        try { return $result->fetch() !== false; } finally { $result->closeCursor(); }
    }

    /** Distinct, opt-in instance flag. Fresh SQL reads also guard post-DNS transfers. */
    public function pointDeliveryRequested(): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('configvalue')->from('appconfig')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter('eva_ai')))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('vector_points_enabled')));
        $result = $qb->executeQuery();
        try { return $result->fetchOne() === '1'; } finally { $result->closeCursor(); }
    }

    /** Uploads follow only the current destination; retained targets are cleanup-only. */
    public function pointTargetIsCurrent(string $target): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('configkey', 'configvalue')->from('appconfig')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter('eva_ai')))
            ->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter(['qdrant_url', 'qdrant_key_target'], IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
        $values = array_column($rows, 'configvalue', 'configkey');
        return isset($values['qdrant_url'], $values['qdrant_key_target'])
            && hash_equals($target, $values['qdrant_key_target']) && hash_equals($target, hash('sha256', $values['qdrant_url']));
    }

    public function pointsEnabled(): bool { return $this->workerEnabled() && $this->pointDeliveryRequested(); }

    /** Acknowledgement means transfer completed, not verified ANN readiness. */
    public function completePoint(array $event, int $now): bool {
        return $this->transaction(function () use ($event, $now): bool {
            if (!$this->ownsLease((int)$event['id'], $event['lease_token'], $now)) return false;
            // CAS the event first so an expired/reclaimed worker cannot mark a
            // SQL chunk as delivered. Source removal safely leaves zero rows.
            if (!$this->complete((int)$event['id'], $event['lease_token'], $now)) return false;
            if ($event['operation'] === 'upsert') {
                $qb = $this->db->getQueryBuilder();
                $qb->update('eva_ai_chunks')->set('vector_state', $qb->createNamedParameter('delivered'))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$event['chunk_id'], IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('document_id', $qb->createNamedParameter((int)$event['document_id'], IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('vector_generation_id', $qb->createNamedParameter($event['generation_id'])))
                    ->andWhere($qb->expr()->eq('vector_revision', $qb->createNamedParameter((int)$event['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
            }
            return true;
        });
    }

    public function complete(int $id, string $token, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_outbox')->set('state', $qb->createNamedParameter('delivered'))
            ->set('last_error', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('delivered_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('lease_token', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))->set('lease_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('processing')));
        return $qb->executeStatement() === 1;
    }

    public function failed(int $id, string $token, string $code, int $now, bool $permanent = false): bool {
        if (preg_match('/^[a-z0-9_]{1,48}$/D', $code) !== 1) throw new \InvalidArgumentException('Use a sanitized vector error code.');
        $qb = $this->db->getQueryBuilder();
        $qb->select('attempts')->from('eva_ai_vec_outbox')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)))->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('processing')));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row) return false;
        $attempts = (int)$row['attempts'];
        $update = $this->db->getQueryBuilder();
        $update->update('eva_ai_vec_outbox')->set('state', $update->createNamedParameter($permanent || $attempts >= self::MAX_ATTEMPTS ? 'dead' : 'pending'))
            ->set('last_error', $update->createNamedParameter($code))->set('available_at', $update->createNamedParameter($now + min(3600, 5 * (2 ** min(10, $attempts))) + random_int(0, 5), IQueryBuilder::PARAM_INT))
            ->set('lease_token', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))->set('lease_until', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($update->expr()->eq('lease_token', $update->createNamedParameter($token)))->andWhere($update->expr()->eq('state', $update->createNamedParameter('processing')));
        return $update->executeStatement() === 1;
    }

    public function supersedeGeneration(string $userId, string $id): void {
        if (!$this->db->inTransaction()) throw new \LogicException('Superseding intent requires a mutation transaction.');
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_outbox')->set('state', $qb->createNamedParameter('superseded'))
            ->set('lease_token', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))->set('lease_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('generation_id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['pending', 'processing', 'dead'], IQueryBuilder::PARAM_STR_ARRAY)));
        $qb->executeStatement();
    }

    public function stats(bool $actionableOnly = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('state')->selectAlias($qb->createFunction('COUNT(*)'), 'events')
            ->selectAlias($qb->createFunction('MIN(created_at)'), 'oldest_created_at')->from('eva_ai_vec_outbox')->groupBy('state');
        if ($actionableOnly) $qb->where($qb->expr()->in('state', $qb->createNamedParameter(['pending', 'processing', 'dead'], IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); } finally { $result->closeCursor(); }
    }

    /** Read control flags without IConfig's process-local cache. */
    public function workerEnabled(): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('configkey', 'configvalue')->from('appconfig')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter('eva_ai')))
            ->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter(['vector_sync_enabled', 'qdrant_enabled'], IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
        if (count($rows) !== 2) return false;
        foreach ($rows as $row) if ($row['configvalue'] !== '1') return false;
        return true;
    }

    public function ownsLease(int $id, string $token, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('eva_ai_vec_outbox')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('processing')))
            ->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)))
            ->andWhere($qb->expr()->gt('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        try { return $result->fetch() !== false; } finally { $result->closeCursor(); }
    }

    /** First observation occurs after SQL commit, unlike enqueue timestamps. */
    public function deferErasure(int $id, string $token, int $now, ?int $readyAt = null): bool {
        $readyAt ??= $now + 60;
        if ($readyAt <= $now) throw new \InvalidArgumentException('Erasure grace must be in the future.');
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_outbox')->set('state', $qb->createNamedParameter('pending'))
            ->set('available_at', $qb->createNamedParameter($readyAt, IQueryBuilder::PARAM_INT))
            ->set('erasure_ready_at', $qb->createNamedParameter($readyAt, IQueryBuilder::PARAM_INT))
            ->set('last_error', $qb->createNamedParameter('erase_grace'))
            ->set('lease_token', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))->set('lease_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('processing')))
            ->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter('delete_generation')))
            ->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($token)))
            ->andWhere($qb->expr()->gt('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    public function page(string $state, int $after = 0): array {
        if (!in_array($state, ['pending', 'processing', 'delivered', 'dead', 'superseded'], true) || $after < 0) throw new \InvalidArgumentException('Invalid outbox listing.');
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'generation_id', 'target_hash', 'operation', 'state', 'attempts', 'created_at', 'available_at', 'lease_until', 'last_error', 'erasure_ready_at')
            ->from('eva_ai_vec_outbox')->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')->setMaxResults(100);
        $result = $qb->executeQuery();
        try { return $result->fetchAll(); } finally { $result->closeCursor(); }
    }

    public function retryDead(int $id, int $now): void {
        if ($id < 1) throw new \InvalidArgumentException('Invalid outbox ID.');
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_outbox')->set('state', $qb->createNamedParameter('pending'))
            ->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))->set('available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('erasure_ready_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('last_error', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('dead')));
        if ($qb->executeStatement() !== 1) throw new \RuntimeException('Only an existing dead-letter event can be retried.');
    }
}
