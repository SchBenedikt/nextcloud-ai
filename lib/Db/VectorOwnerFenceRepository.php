<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorStoreException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Serializes generation creation/binding against durable account erasure. */
class VectorOwnerFenceRepository {
    public function __construct(private IDBConnection $db, private VectorOutboxRepository $outbox) {}

    public static function ownerHash(string $userId): string {
        if ($userId === '' || strlen($userId) > 4096) throw new \InvalidArgumentException('Invalid vector owner.');
        return hash('sha256', 'eva_ai:vector-owner:' . $userId);
    }

    /** Called in the same transaction as creation/binding; holds a row lock. */
    public function lockOpen(string $userId): void {
        if (!$this->db->inTransaction()) throw new \LogicException('Vector ownership requires the mutation transaction.');
        $key = self::ownerHash($userId);
        $this->ensureRow($key);
        $qb = $this->db->getQueryBuilder();
        // A real increment gives consistent affected-row semantics on MariaDB
        // and locks the row without relying on Nextcloud 33's forUpdate API.
        $qb->update('eva_ai_vec_owners')->set('revision', $qb->func()->add('revision', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('owner_hash', $qb->createNamedParameter($key)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('open')));
        if ($qb->executeStatement() !== 1) throw new VectorStoreException('owner_erased');
    }

    /** Serialize an EVA user-config write with account deletion on the same DB connection. */
    public function mutateOpen(string $userId,callable $mutation): mixed {
        if($this->db->inTransaction()){$this->lockOpen($userId);return $mutation();}
        return $this->outbox->transaction(function()use($userId,$mutation):mixed{$this->lockOpen($userId);return $mutation();});
    }

    /** Commit the fence before best-effort cleanup can fail or be interrupted. */
    public function beginErasure(string $userId): void {
        if ($this->db->inTransaction()) throw new \LogicException('Account erasure must start outside an existing transaction.');
        $this->outbox->transaction(function () use ($userId): void {
            $key = self::ownerHash($userId);
            $this->ensureRow($key);
            $qb = $this->db->getQueryBuilder();
            $qb->update('eva_ai_vec_owners')->set('state', $qb->createNamedParameter('blocked'))
                ->set('revision', $qb->func()->add('revision', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('owner_hash', $qb->createNamedParameter($key)));
            if ($qb->executeStatement() !== 1) throw new \RuntimeException('Vector ownership fence could not be persisted.');
        });
    }

    /** Fresh SQL state; a missing fence is not permission to start a transfer. */
    public function status(string $userId): string {
        return $this->metadata($userId)['state'] ?? 'uninitialized';
    }

    /** Ownership-filtered personal export; no raw account name is retained. */
    public function metadata(string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_hash', 'state', 'revision', 'updated_at')->from('eva_ai_vec_owners')
            ->where($qb->expr()->eq('owner_hash', $qb->createNamedParameter(self::ownerHash($userId))));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row) return null;
        if (!in_array($row['state'], ['open', 'blocked'], true)) throw new \RuntimeException('Invalid vector ownership state.');
        return ['owner_hash' => $row['owner_hash'], 'state' => $row['state'],
            'revision' => (int)$row['revision'], 'updated_at' => (int)$row['updated_at']];
    }

    private function ensureRow(string $key): void {
        // Unique-key conflict handling is provided by Nextcloud for each SQL
        // backend. It never replaces an existing blocked row with an open one.
        $this->db->insertIgnoreConflict('eva_ai_vec_owners', ['owner_hash' => $key,
            'state' => 'open', 'revision' => 0, 'updated_at' => time()]);
    }

    /** Local admin metadata, bounded SQL batches rather than one query per user. */
    public function statesForUsers(array $userIds): array {
        $states = [];
        foreach (array_chunk(array_values(array_unique($userIds)), 100) as $batch) {
            $owners = [];
            foreach ($batch as $userId) { $owners[self::ownerHash($userId)] = $userId; $states[$userId] = 'uninitialized'; }
            $qb = $this->db->getQueryBuilder();
            $qb->select('owner_hash', 'state')->from('eva_ai_vec_owners')
                ->where($qb->expr()->in('owner_hash', $qb->createNamedParameter(array_keys($owners), IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
            foreach ($rows as $row) {
                if (!isset($owners[$row['owner_hash']]) || !in_array($row['state'], ['open', 'blocked'], true)) throw new \RuntimeException('Invalid vector ownership state.');
                $states[$owners[$row['owner_hash']]] = $row['state'];
            }
        }
        return $states;
    }
}
