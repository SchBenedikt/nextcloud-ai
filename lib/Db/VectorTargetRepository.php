<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Server-owned destinations retained for outstanding old-target deletion work. */
class VectorTargetRepository {
    public function __construct(private IDBConnection $db) {}

    public function save(string $endpoint, string $auth, string $encryptedKey): string {
        if (!in_array($auth, ['none', 'api_key'], true) || ($auth === 'api_key' && $encryptedKey === '') || ($auth === 'none' && $encryptedKey !== '')) throw new \InvalidArgumentException('Invalid vector target authentication.');
        $id = hash('sha256', $endpoint);
        $existing = $this->find($id);
        if ($existing !== null && $existing['endpoint'] !== $endpoint) throw new \RuntimeException('Vector target identity conflict.');
        $qb = $this->db->getQueryBuilder();
        if ($existing === null) {
            $qb->insert('eva_ai_vec_targets')->setValue('id', $qb->createNamedParameter($id))
                ->setValue('endpoint', $qb->createNamedParameter($endpoint))->setValue('auth', $qb->createNamedParameter($auth))
                ->setValue('encrypted_key', $qb->createNamedParameter($encryptedKey))->setValue('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
        } else {
            $qb->update('eva_ai_vec_targets')->set('auth', $qb->createNamedParameter($auth))
                ->set('encrypted_key', $qb->createNamedParameter($encryptedKey))->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        }
        $qb->executeStatement();
        return $id;
    }

    public function find(string $id): ?array {
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) throw new \InvalidArgumentException('Invalid vector target ID.');
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('eva_ai_vec_targets')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row) return null;
        if (!is_string($row['endpoint']) || !hash_equals($id, hash('sha256', $row['endpoint'])) || !in_array($row['auth'], ['none', 'api_key'], true)) throw new \RuntimeException('Stored vector target is inconsistent.');
        return $row;
    }
}
