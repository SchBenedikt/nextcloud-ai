<?php
declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Coalesces current source changes into durable per-generation work intent. */
final class VectorGenerationMutationRepository {
    private const LEASE_SECONDS = 180;
    private const MAX_ATTEMPTS = 12;

    private VectorOutboxRepository $outbox;
    private VectorOwnerFenceRepository $owners;

    public function __construct(private IDBConnection $db, ?VectorOutboxRepository $outbox = null,
        ?VectorOwnerFenceRepository $owners = null) {
        $this->outbox = $outbox ?? new VectorOutboxRepository($db);
        $this->owners = $owners ?? new VectorOwnerFenceRepository($db, $this->outbox);
    }

    /** Called inside the canonical source transaction; no provider or storage I/O. */
    public function enqueueActive(string $userId, int $documentId): void {
        if ($userId === '' || $documentId < 1 || !$this->db->inTransaction()) {
            throw new \LogicException('Generation mutation enqueue requires a source transaction.');
        }
        $q = $this->db->getQueryBuilder();
        $q->select('g.id')->from('eva_ai_vec_state', 's')
            ->innerJoin('s', 'eva_ai_vec_generations', 'g', $q->expr()->andX(
                $q->expr()->eq('g.id', 's.active_generation_id'),
                $q->expr()->eq('g.user_id', 's.user_id')))
            ->where($q->expr()->eq('s.user_id', $q->createNamedParameter($userId)))
            ->andWhere($q->expr()->eq('g.status', $q->createNamedParameter('active')))
            ->setMaxResults(1);
        $result = $q->executeQuery();
        try { $generationId = $result->fetchOne(); } finally { $result->closeCursor(); }
        if (!is_string($generationId) || preg_match('/^[a-f0-9]{32}$/D', $generationId) !== 1) return;

        $now = time();
        $update = $this->db->getQueryBuilder();
        $update->update('eva_ai_vec_mutations')
            ->set('status', $update->createNamedParameter('queued'))
            ->set('revision', $update->func()->add('revision', $update->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->set('attempts', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('chunk_cursor', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('plan_hash', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('source_stamp', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('available_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('lease_token', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('lease_until', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('error_code', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('updated_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($update->expr()->eq('generation_id', $update->createNamedParameter($generationId)))
            ->andWhere($update->expr()->eq('user_id', $update->createNamedParameter($userId)))
            ->andWhere($update->expr()->eq('document_id', $update->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        if ($update->executeStatement() > 0) return;

        $this->db->insertIgnoreConflict('eva_ai_vec_mutations', [
            'generation_id' => $generationId, 'user_id' => $userId, 'document_id' => $documentId,
            'status' => 'queued', 'revision' => 1, 'attempts' => 0, 'available_at' => $now,
            'chunk_cursor'=>0,'plan_hash'=>null,'source_stamp'=>null,
            'lease_until' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // A concurrent source update may have won the unique-key insert after our
        // first UPDATE; normalize that row to the newest coalesced revision.
        $retry = $this->db->getQueryBuilder();
        $retry->update('eva_ai_vec_mutations')
            ->set('status', $retry->createNamedParameter('queued'))
            ->set('revision', $retry->func()->add('revision', $retry->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->set('attempts', $retry->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('chunk_cursor', $retry->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('plan_hash', $retry->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('source_stamp', $retry->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('available_at', $retry->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('lease_token', $retry->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('lease_until', $retry->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('error_code', $retry->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('updated_at', $retry->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($retry->expr()->eq('generation_id', $retry->createNamedParameter($generationId)))
            ->andWhere($retry->expr()->eq('user_id', $retry->createNamedParameter($userId)))
            ->andWhere($retry->expr()->eq('document_id', $retry->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        $retry->executeStatement();
    }

    /** Canonical deletion also cancels queued upserts for the removed source. */
    public function deleteSources(array $documentIds): void {
        if ($documentIds === [] || !$this->db->inTransaction()) return;
        $ids = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id): bool => $id > 0)));
        foreach (array_chunk($ids, 500) as $batch) {
            $q = $this->db->getQueryBuilder();
            $q->delete('eva_ai_vec_mutations')->where($q->expr()->in('document_id', $q->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
        }
    }

    /** Claim one coalesced source mutation with an expiring CAS lease. */
    public function claim(int $now): ?array {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $q = $this->db->getQueryBuilder();
            $q->select('m.id','m.user_id','m.generation_id','m.document_id','m.revision')
                ->from('eva_ai_vec_mutations','m')
                ->innerJoin('m','eva_ai_vec_generations','g',$q->expr()->andX(
                    $q->expr()->eq('g.id','m.generation_id'),$q->expr()->eq('g.user_id','m.user_id')))
                ->innerJoin('m','eva_ai_vec_state','s',$q->expr()->andX(
                    $q->expr()->eq('s.user_id','m.user_id'),$q->expr()->eq('s.active_generation_id','m.generation_id')))
                ->innerJoin('m','preferences','p',$q->expr()->andX(
                    $q->expr()->eq('p.userid','m.user_id'),
                    $q->expr()->eq('p.appid',$q->createNamedParameter('eva_ai')),
                    $q->expr()->eq('p.configkey',$q->createNamedParameter('index_enrolled')),
                    $q->expr()->eq('p.configvalue',$q->createNamedParameter('1'))))
                ->where($q->expr()->eq('g.status',$q->createNamedParameter('active')))
                ->andWhere($q->expr()->lte('m.available_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->orX($q->expr()->eq('m.status',$q->createNamedParameter('queued')),
                    $q->expr()->andX($q->expr()->eq('m.status',$q->createNamedParameter('processing')),
                        $q->expr()->lte('m.lease_until',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))))
                ->orderBy('m.available_at','ASC')->addOrderBy('m.id','ASC')->setMaxResults(50);
            $result = $q->executeQuery();
            try { $candidates = $result->fetchAll(); } finally { $result->closeCursor(); }
            if (!$candidates) return null;
            foreach ($candidates as $candidate) {
                try {
                    $claim = $this->outbox->transaction(function () use ($candidate,$now): ?array {
                        $user = (string)$candidate['user_id'];
                        try { $this->owners->lockOpen($user); }
                        catch (\OCA\EvaAi\Vector\VectorStoreException) { return null; }
                        $token = bin2hex(random_bytes(16));
                        $update = $this->db->getQueryBuilder();
                        $update->update('eva_ai_vec_mutations')
                            ->set('status',$update->createNamedParameter('processing'))
                            ->set('lease_token',$update->createNamedParameter($token))
                            ->set('lease_until',$update->createNamedParameter($now+self::LEASE_SECONDS,IQueryBuilder::PARAM_INT))
                            ->set('revision',$update->func()->add('revision',$update->createNamedParameter(1,IQueryBuilder::PARAM_INT)))
                            ->set('updated_at',$update->createNamedParameter($now,IQueryBuilder::PARAM_INT))
                            ->where($update->expr()->eq('id',$update->createNamedParameter((int)$candidate['id'],IQueryBuilder::PARAM_INT)))
                            ->andWhere($update->expr()->eq('revision',$update->createNamedParameter((int)$candidate['revision'],IQueryBuilder::PARAM_INT)))
                            ->andWhere($update->expr()->orX($update->expr()->eq('status',$update->createNamedParameter('queued')),
                                $update->expr()->andX($update->expr()->eq('status',$update->createNamedParameter('processing')),
                                    $update->expr()->lte('lease_until',$update->createNamedParameter($now,IQueryBuilder::PARAM_INT)))));
                        if ($update->executeStatement() !== 1) return null;
                        return $this->findById((int)$candidate['id']);
                    });
                    if ($claim !== null) return $claim;
                } catch (\Throwable) {
                    // One concurrently erased owner cannot starve other tenants' queued work.
                }
            }
        }
        return null;
    }

    public function findById(int $id): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('eva_ai_vec_mutations')->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        $r = $q->executeQuery();
        try { $row = $r->fetch(); } finally { $r->closeCursor(); }
        if (!$row) return null;
        foreach (['id','document_id','revision','attempts','chunk_cursor','available_at','lease_until','created_at','updated_at'] as $key) $row[$key] = (int)$row[$key];
        return $row;
    }

    /** Fresh ownership, enrollment, active-pointer and lease check before work/publish. */
    public function assertLease(array $claim, int $now): array {
        $q = $this->db->getQueryBuilder();
        $q->select('m.*')->from('eva_ai_vec_mutations','m')
            ->innerJoin('m','eva_ai_vec_generations','g',$q->expr()->andX(
                $q->expr()->eq('g.id','m.generation_id'),$q->expr()->eq('g.user_id','m.user_id')))
            ->innerJoin('m','eva_ai_vec_state','s',$q->expr()->andX(
                $q->expr()->eq('s.user_id','m.user_id'),$q->expr()->eq('s.active_generation_id','m.generation_id')))
            ->innerJoin('m','eva_ai_vec_owners','o',$q->expr()->eq('o.owner_hash',$q->createNamedParameter(VectorOwnerFenceRepository::ownerHash($claim['user_id']))))
            ->innerJoin('m','preferences','p',$q->expr()->andX(
                $q->expr()->eq('p.userid','m.user_id'),$q->expr()->eq('p.appid',$q->createNamedParameter('eva_ai')),
                $q->expr()->eq('p.configkey',$q->createNamedParameter('index_enrolled')),$q->expr()->eq('p.configvalue',$q->createNamedParameter('1'))))
            ->where($q->expr()->eq('m.id',$q->createNamedParameter((int)$claim['id'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.user_id',$q->createNamedParameter($claim['user_id'])))
            ->andWhere($q->expr()->eq('g.status',$q->createNamedParameter('active')))
            ->andWhere($q->expr()->eq('o.state',$q->createNamedParameter('open')));
        $r = $q->executeQuery();
        try { $row = $r->fetch(); } finally { $r->closeCursor(); }
        if (!$row || $row['status'] !== 'processing' || $row['lease_token'] !== $claim['lease_token']
            || (int)$row['revision'] !== (int)$claim['revision'] || (int)$row['lease_until'] <= $now) {
            throw new \OCA\EvaAi\Vector\VectorStoreException('stale_mutation_lease');
        }
        foreach (['id','document_id','revision','attempts','chunk_cursor','available_at','lease_until','created_at','updated_at'] as $key) $row[$key] = (int)$row[$key];
        return $row;
    }

    /** Renew a lease before each bounded extraction/embedding batch. */
    public function heartbeat(array $claim, int $now): void {
        $this->outbox->transaction(function () use ($claim,$now): void {
            $this->owners->lockOpen($claim['user_id']);
            $this->assertLease($claim,$now);
            $q = $this->db->getQueryBuilder();
            $q->update('eva_ai_vec_mutations')->set('lease_until',$q->createNamedParameter($now+self::LEASE_SECONDS,IQueryBuilder::PARAM_INT))
                ->set('updated_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',$q->createNamedParameter($claim['id'],IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('lease_token',$q->createNamedParameter($claim['lease_token'])))->executeStatement();
        });
    }

    /** Persist an idempotent cursor only after its chunk batch committed. */
    public function checkpoint(array $claim,int $cursor,string $planHash,string $sourceStamp): void {
        if (!$this->db->inTransaction() || $cursor < 0 || preg_match('/^[a-f0-9]{64}$/D',$planHash)!==1
            || preg_match('/^[a-f0-9]{64}$/D',$sourceStamp)!==1) throw new \LogicException('Invalid mutation checkpoint.');
        $this->owners->lockOpen($claim['user_id']);
        $this->assertLease($claim,time());
        $q = $this->db->getQueryBuilder();
        $q->update('eva_ai_vec_mutations')->set('chunk_cursor',$q->createNamedParameter($cursor,IQueryBuilder::PARAM_INT))
            ->set('plan_hash',$q->createNamedParameter($planHash))->set('source_stamp',$q->createNamedParameter($sourceStamp))
            ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id',$q->createNamedParameter($claim['id'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('lease_token',$q->createNamedParameter($claim['lease_token'])))->executeStatement();
    }

    /** Yield a successful partial pass without charging a failed attempt. */
    public function yield(array $claim): void {
        $this->outbox->transaction(function () use ($claim): void {
            $row=$this->findById($claim['id']);
            if($row===null||$row['status']!=='processing'||$row['lease_token']!==$claim['lease_token'])return;
            $q=$this->db->getQueryBuilder();
            $q->update('eva_ai_vec_mutations')->set('status',$q->createNamedParameter('queued'))
                ->set('lease_token',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                ->set('lease_until',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->set('available_at',$q->createNamedParameter(time()+1,IQueryBuilder::PARAM_INT))
                ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',$q->createNamedParameter($claim['id'],IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('lease_token',$q->createNamedParameter($claim['lease_token'])))->executeStatement();
        });
    }

    /** Mark source rows visible to generation reads only after all chunks commit. */
    public function complete(array $claim): void {
        if (!$this->db->inTransaction()) throw new \LogicException('Mutation completion must share the chunk transaction.');
        $this->owners->lockOpen($claim['user_id']);
        $this->assertLease($claim,time());
        $q = $this->db->getQueryBuilder();
        $q->update('eva_ai_vec_mutations')->set('status',$q->createNamedParameter('completed'))
            ->set('lease_token',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
            ->set('lease_until',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))
            ->set('error_code',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
            ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id',$q->createNamedParameter($claim['id'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('lease_token',$q->createNamedParameter($claim['lease_token'])));
        if ($q->executeStatement() !== 1) throw new \OCA\EvaAi\Vector\VectorStoreException('stale_mutation_lease');
    }

    /** Retry transient failures with bounded exponential backoff and a terminal state. */
    public function release(array $claim, string $errorCode): void {
        if (preg_match('/^[a-z0-9_]{1,48}$/D',$errorCode)!==1) $errorCode='mutation_failed';
        $this->outbox->transaction(function () use ($claim,$errorCode): void {
            $row = $this->findById($claim['id']);
            if ($row===null||$row['status']!=='processing'||$row['lease_token']!==$claim['lease_token']) return;
            $attempts = $row['attempts'] + 1;
            $failed = $attempts >= self::MAX_ATTEMPTS;
            $delay = min(3600,15*(2**min(8,max(0,$row['attempts']-1))));
            $q = $this->db->getQueryBuilder();
            $q->update('eva_ai_vec_mutations')->set('status',$q->createNamedParameter($failed?'failed':'queued'))
                ->set('available_at',$q->createNamedParameter($failed?time():time()+$delay,IQueryBuilder::PARAM_INT))
                ->set('lease_token',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))
                ->set('lease_until',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->set('error_code',$q->createNamedParameter($errorCode))
                ->set('attempts',$q->createNamedParameter($attempts,IQueryBuilder::PARAM_INT))
                ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id',$q->createNamedParameter($claim['id'],IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('lease_token',$q->createNamedParameter($claim['lease_token'])))->executeStatement();
        });
    }
}
