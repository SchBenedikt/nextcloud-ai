<?php

declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCA\EvaAi\Vector\VectorGeneration;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Tenant-bound lifecycle metadata; activation awaits an actual verified backfill. */
class VectorGenerationRepository {
    public function __construct(private IDBConnection $db, private VectorOutboxRepository $outbox, private VectorOwnerFenceRepository $owners) {}

    public function create(VectorGeneration $generation): void {
        $this->outbox->transaction(function () use ($generation): void {
            $this->owners->lockOpen($generation->userId);
            $this->insertBuilding($generation);
            if ($generation->backend === 'sql') {
                $this->bindTargetLocked($generation->userId, $generation->id, self::sqlTargetHash());
            }
        });
    }

    public static function sqlTargetHash(): string {
        return hash('sha256', 'eva_ai:sql-generation-store:v1');
    }

    private function insertBuilding(VectorGeneration $generation): void {
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

    /** Find an unfinished SQL generation with the same immutable embedding identity. */
    public function findBuildingSql(string $userId, string $model, string $endpointHash, int $dimension, int $chunkSize, int $chunkOverlap): ?VectorGeneration {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('eva_ai_vec_generations')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('backend', $qb->createNamedParameter('sql')))
            ->andWhere($qb->expr()->eq('embedding_provider', $qb->createNamedParameter('ollama')))
            ->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($model)))
            ->andWhere($qb->expr()->eq('endpoint_hash', $qb->createNamedParameter($endpointHash)))
            ->andWhere($qb->expr()->eq('dimension', $qb->createNamedParameter($dimension, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('chunk_size', $qb->createNamedParameter($chunkSize, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('chunk_overlap', $qb->createNamedParameter($chunkOverlap, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('building')))
            ->orderBy('created_at', 'ASC')->setMaxResults(1);
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        return $row ? VectorGeneration::fromRow($row) : null;
    }

    /** The user's enrolled, active local SQL generation, if the primary pointer is current. */
    public function activeSqlForUser(string $userId): ?VectorGeneration {
        $generation=$this->activeGenerationForUser($userId);
        return $generation?->backend==='sql'?$generation:null;
    }

    public function activeQdrantForUser(string $userId): ?VectorGeneration {
        $generation=$this->activeGenerationForUser($userId);
        return $generation?->backend==='qdrant'?$generation:null;
    }

    /** Any local generation means bootstrap has already been attempted. */
    public function hasSqlGeneration(string $userId): bool {
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('eva_ai_vec_generations')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('backend', $q->createNamedParameter('sql')))
            ->setMaxResults(1);
        $r = $q->executeQuery();
        try { return $r->fetchOne() !== false; } finally { $r->closeCursor(); }
    }

    /** The explicitly selected local or remote primary generation with no unfinished source changes. */
    public function activeGenerationForUser(string $userId): ?VectorGeneration {
        $q=$this->db->getQueryBuilder();
        $q->select('g.*')->from('eva_ai_vec_state','s')
            ->innerJoin('s','eva_ai_vec_generations','g',$q->expr()->andX(
                $q->expr()->eq('g.id','s.active_generation_id'),$q->expr()->eq('g.user_id','s.user_id')))
            ->innerJoin('s','preferences','p',$q->expr()->andX(
                $q->expr()->eq('p.userid','s.user_id'),$q->expr()->eq('p.appid',$q->createNamedParameter('eva_ai')),
                $q->expr()->eq('p.configkey',$q->createNamedParameter('index_enrolled')),$q->expr()->eq('p.configvalue',$q->createNamedParameter('1'))))
            ->leftJoin('s','eva_ai_vec_mutations','m',$q->expr()->andX(
                $q->expr()->eq('m.generation_id','g.id'),$q->expr()->eq('m.user_id','g.user_id'),
                $q->expr()->neq('m.status',$q->createNamedParameter('completed'))))
            ->where($q->expr()->eq('s.user_id',$q->createNamedParameter($userId)))
            ->andWhere($q->expr()->orX(
                $q->expr()->andX($q->expr()->eq('s.retrieval_backend',$q->createNamedParameter('sql_generation')),$q->expr()->eq('g.backend',$q->createNamedParameter('sql'))),
                $q->expr()->andX($q->expr()->eq('s.retrieval_backend',$q->createNamedParameter('qdrant_generation')),$q->expr()->eq('g.backend',$q->createNamedParameter('qdrant')))))
            ->andWhere($q->expr()->eq('g.status',$q->createNamedParameter('active')))
            ->andWhere($q->expr()->isNull('m.id'))->setMaxResults(1);
        $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        return $row?VectorGeneration::fromRow($row):null;
    }

    public function activeGenerationId(string $userId): ?string {
        $q=$this->db->getQueryBuilder();
        $q->select('active_generation_id')->from('eva_ai_vec_state')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($userId)));
        $r=$q->executeQuery();try{$id=$r->fetchOne();}finally{$r->closeCursor();}
        return is_string($id)&&preg_match('/^[a-f0-9]{32}$/D',$id)===1?$id:null;
    }

    /** Number of enrolled accounts currently reading their local SQL generation. */
    public function activeSqlCount(): int {
        return $this->activeBackendCount('sql_generation','sql');
    }

    public function activeQdrantCount(): int {
        return $this->activeBackendCount('qdrant_generation','qdrant');
    }

    private function activeBackendCount(string $retrievalBackend,string $generationBackend): int {
        $q=$this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('COUNT(DISTINCT s.user_id)'),'total')->from('eva_ai_vec_state','s')
            ->innerJoin('s','eva_ai_vec_generations','g',$q->expr()->andX(
                $q->expr()->eq('g.id','s.active_generation_id'),$q->expr()->eq('g.user_id','s.user_id')))
            ->innerJoin('s','preferences','p',$q->expr()->andX(
                $q->expr()->eq('p.userid','s.user_id'),$q->expr()->eq('p.appid',$q->createNamedParameter('eva_ai')),
                $q->expr()->eq('p.configkey',$q->createNamedParameter('index_enrolled')),$q->expr()->eq('p.configvalue',$q->createNamedParameter('1'))))
            ->leftJoin('s','eva_ai_vec_mutations','m',$q->expr()->andX(
                $q->expr()->eq('m.generation_id','g.id'),$q->expr()->eq('m.user_id','g.user_id'),
                $q->expr()->neq('m.status',$q->createNamedParameter('completed'))))
            ->where($q->expr()->eq('s.retrieval_backend',$q->createNamedParameter($retrievalBackend)))
            ->andWhere($q->expr()->eq('g.backend',$q->createNamedParameter($generationBackend)))
            ->andWhere($q->expr()->eq('g.status',$q->createNamedParameter('active')))
            ->andWhere($q->expr()->isNull('m.id'));
        $r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }

    public function retrievalBackendForUser(string $userId): string {
        $q=$this->db->getQueryBuilder();$q->select('retrieval_backend')->from('eva_ai_vec_state')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($userId)));
        $r=$q->executeQuery();try{$backend=$r->fetchOne();}finally{$r->closeCursor();}
        return is_string($backend)&&in_array($backend,['sql_legacy','sql_generation','qdrant_generation'],true)?$backend:'sql_legacy';
    }

    /** Pending or failed source mutations make an active snapshot incomplete. */
    public function pendingMutationCount(string $userId,string $generationId): int {
        $q=$this->db->getQueryBuilder();
        $q->selectAlias($q->createFunction('COUNT(*)'),'total')->from('eva_ai_vec_mutations')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($generationId)))
            ->andWhere($q->expr()->neq('status',$q->createNamedParameter('completed')));
        $r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
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

    /** Immutable event metadata must still match the current generation and retained target. */
    public function deliveryState(VectorGeneration $generation, string $target): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('eva_ai_vec_generations')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($generation->userId)))
            ->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($generation->id)));
        $result = $qb->executeQuery();
        try { $row = $result->fetch(); } finally { $result->closeCursor(); }
        if (!$row) return null;
        if (VectorGeneration::fromRow($row)->toRow() !== $generation->toRow() || $row['storage_endpoint_hash'] !== $target
            || !in_array($row['status'], ['building', 'active', 'retired', 'failed'], true)) {
            throw new \OCA\EvaAi\Vector\VectorStoreException('generation_identity_mismatch');
        }
        return $row['status'];
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
        $this->outbox->transaction(function () use ($userId, $id, $target): void {
            $this->owners->lockOpen($userId);
            $this->bindTargetLocked($userId, $id, $target);
        });
    }

    private function bindTargetLocked(string $userId, string $id, string $target): void {
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
        $qb->select('g.id')->from('eva_ai_vec_generations', 'g')
            ->innerJoin('g', 'eva_ai_vec_owners', 'o', $qb->expr()->eq('o.owner_hash', $qb->createNamedParameter(VectorOwnerFenceRepository::ownerHash($userId))))
            ->where($qb->expr()->eq('g.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('g.id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('g.status', $qb->createNamedParameter('building')))
            ->andWhere($qb->expr()->eq('g.storage_endpoint_hash', $qb->createNamedParameter($target)))
            ->andWhere($qb->expr()->eq('o.state', $qb->createNamedParameter('open')));
        $result = $qb->executeQuery();
        try { return $result->fetch() !== false; } finally { $result->closeCursor(); }
    }

    /** SQL erasure and durable remote intent commit or roll back together. */
    public function deleteUser(string $userId, ?callable $deleteIndex = null): void {
        // This commits separately: a subsequent cleanup rollback must never
        // permit an in-flight request to create/bind another generation.
        $this->owners->beginErasure($userId);
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
                    if ($row['storage_endpoint_hash'] !== null && $row['backend'] === 'qdrant') $this->outbox->enqueue(VectorGeneration::fromRow($row), $row['storage_endpoint_hash'], 'delete_generation');
                    $cursor = $row['id'];
                }
            } while (count($rows) === 50);
            if ($deleteIndex !== null) $deleteIndex();
            foreach (['eva_ai_vec_mutations','eva_ai_vec_build_docs','eva_ai_vec_builds','eva_ai_vec_verifications','eva_ai_vec_state','eva_ai_vec_generations'] as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
            }
        });
    }

    public function ownerStatus(string $userId): string { return $this->owners->status($userId); }
    public function ownerMetadata(string $userId): ?array { return $this->owners->metadata($userId); }

    public function target(string $userId,string $id): ?string {
        $q=$this->db->getQueryBuilder();$q->select('storage_endpoint_hash','backend')->from('eva_ai_vec_generations')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($userId)))->andWhere($q->expr()->eq('id',$q->createNamedParameter($id)));
        $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        if (!$row) return null;
        if ($row['backend'] === 'sql') return $row['storage_endpoint_hash'] === self::sqlTargetHash() ? self::sqlTargetHash() : null;
        $target=$row['storage_endpoint_hash'];
        return is_string($target) && preg_match('/^[a-f0-9]{64}$/D',$target)===1?$target:null;
    }
}
