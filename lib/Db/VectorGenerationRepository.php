<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorGeneration;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Tenant-bound lifecycle metadata; activation awaits an actual verified backfill. */
class VectorGenerationRepository {
    public function __construct(private IDBConnection $db, private VectorOutboxRepository $outbox) {}

    public function create(VectorGeneration $generation): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('eva_ai_vec_generations');
        foreach ($generation->toRow() + ['status' => 'building', 'created_at' => time(), 'build_cursor' => 0] as $name => $value) {
            $type = is_int($value) ? IQueryBuilder::PARAM_INT : ($value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR);
            $qb->setValue($name, $qb->createNamedParameter($value, $type));
        }
        $qb->executeStatement();
    }

    public function find(string $userId, string $id): ?VectorGeneration {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('eva_ai_vec_generations')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        return $row ? VectorGeneration::fromRow($row) : null;
    }

    /** Keyset listing, not an unbounded per-account history read. */
    public function page(string $userId, string $after = ''): array {
        if ($after !== '' && preg_match('/^[a-f0-9]{32}$/D', $after) !== 1) throw new \InvalidArgumentException('Invalid generation cursor.');
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('eva_ai_vec_generations')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        if ($after !== '') $qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($after)));
        $qb->orderBy('id', 'ASC')->setMaxResults(50);
        $result = $qb->executeQuery();
        try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
        foreach ($rows as $row) {
            VectorGeneration::fromRow($row);
            if (!in_array($row['status'], ['building', 'active', 'retired', 'failed'], true)) throw new \RuntimeException('Invalid stored generation status.');
        }
        return $rows;
    }

    public function failBuilding(string $userId, string $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_generations')->set('status', $qb->createNamedParameter('failed'))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('building')));
        if ($qb->executeStatement() !== 1) throw new \RuntimeException('Generation is missing, belongs to another user or is no longer building.');
    }

    public function status(string $userId, string $id): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('status')->from('eva_ai_vec_generations')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row) return null;
        if (!in_array($row['status'], ['building', 'active', 'retired', 'failed'], true)) throw new \RuntimeException('Invalid stored generation status.');
        return $row['status'];
    }

    public function bindTarget(string $userId, string $id, string $target): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $target) !== 1) throw new \InvalidArgumentException('Invalid storage target.');
        $qb = $this->db->getQueryBuilder();
        $qb->update('eva_ai_vec_generations')->set('storage_endpoint_hash', $qb->createNamedParameter($target))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('building')))
            ->andWhere($qb->expr()->isNull('storage_endpoint_hash'));
        if ($qb->executeStatement() === 1) return;
        $read = $this->db->getQueryBuilder();
        $read->select('storage_endpoint_hash', 'status')->from('eva_ai_vec_generations')
            ->where($read->expr()->eq('user_id', $read->createNamedParameter($userId)))
            ->andWhere($read->expr()->eq('id', $read->createNamedParameter($id)));
        $result = $read->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row || $row['status'] !== 'building' || $row['storage_endpoint_hash'] !== $target) throw new \RuntimeException('Generation target is already bound, retired or missing.');
    }

    public function isBuildingBound(string $userId, string $id, string $target): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('eva_ai_vec_generations')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('building')))
            ->andWhere($qb->expr()->eq('storage_endpoint_hash', $qb->createNamedParameter($target)));
        $result = $qb->executeQuery();
        try { return $result->fetch() !== false; } finally { $result->closeCursor(); }
    }

    /** SQL erasure and durable remote intent commit or roll back together. */
    public function deleteUser(string $userId, ?callable $deleteIndex = null): void {
        $this->outbox->transaction(function () use ($userId, $deleteIndex): void {
            // Serialize against a concurrent binding before reading destinations.
            $retire = $this->db->getQueryBuilder();
            $retire->update('eva_ai_vec_generations')->set('status', $retire->createNamedParameter('retired'))
                ->where($retire->expr()->eq('user_id', $retire->createNamedParameter($userId)))->executeStatement();
            $cursor = '';
            do {
                $rows = $this->page($userId, $cursor);
                foreach ($rows as $row) {
                    $this->outbox->supersedeGeneration($userId, $row['id']);
                    if ($row['storage_endpoint_hash'] !== null) $this->outbox->enqueue(VectorGeneration::fromRow($row), $row['storage_endpoint_hash'], 'delete_generation');
                    $cursor = $row['id'];
                }
            } while (count($rows) === 50);
            if ($deleteIndex !== null) $deleteIndex();
            foreach (['eva_ai_vec_state', 'eva_ai_vec_generations'] as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
            }
        });
    }
}
