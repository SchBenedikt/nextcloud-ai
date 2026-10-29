<?php
declare(strict_types=1);

namespace OCA\EvaAi\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Coalesces current source changes into durable per-generation work intent. */
final class VectorGenerationMutationRepository {
    public function __construct(private IDBConnection $db) {}

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
            'lease_until' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // A concurrent source update may have won the unique-key insert after our
        // first UPDATE; normalize that row to the newest coalesced revision.
        $retry = $this->db->getQueryBuilder();
        $retry->update('eva_ai_vec_mutations')
            ->set('status', $retry->createNamedParameter('queued'))
            ->set('revision', $retry->func()->add('revision', $retry->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
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
}
