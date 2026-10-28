<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorGeneration;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Tenant-bound lifecycle metadata; activation awaits an actual verified backfill. */
class VectorGenerationRepository {
    public function __construct(private IDBConnection $db) {}

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

    /** Currently metadata only. Remote erasure will require an outbox tombstone. */
    public function deleteUser(string $userId): void {
        foreach (['eva_ai_vec_state', 'eva_ai_vec_generations'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
        }
    }
}
