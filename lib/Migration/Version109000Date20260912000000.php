<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tell the origin of an index entry apart.
 *
 * The documents table used to hold only files, and every non-file entry was
 * recognised by its negative file id - which is how mail was reconciled when a
 * message was deleted. Talk chat histories are indexed the same way (they are
 * not files either), so "negative file id" stops identifying mail: the negative
 * id space is now shared by two producers, and a reconciliation pass would
 * delete the other one's rows.
 *
 * `source` names the producer ('files', 'mail', 'talk'), so each reconciliation
 * only touches its own rows. Existing negative-id rows can only be mail, because
 * Talk indexing did not exist yet, and they are backfilled accordingly - without
 * that, a legacy mail row would be filed as a file and never cleaned up.
 */
final class Version109000Date20260912000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_documents')) {
			$table = $schema->getTable('eva_ai_documents');
			if (!$table->hasColumn('source')) {
				$table->addColumn('source', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'files',
				]);
			}
			if (!$table->hasIndex('eva_ai_doc_user_source')) {
				// Reconciliation and per-source listings filter on exactly this
				// pair, and the table grows with every mail message and room.
				$table->addIndex(['user_id', 'source'], 'eva_ai_doc_user_source');
			}
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		try {
			$qb = $this->connection->getQueryBuilder();
			$qb->update('eva_ai_documents')
				->set('source', $qb->createNamedParameter('mail'))
				->where($qb->expr()->lt('file_id', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('source', $qb->createNamedParameter('files')));
			$updated = $qb->executeStatement();
			if ($updated > 0) {
				$output->info('eva_ai: marked ' . $updated . ' existing mail documents with source=mail');
			}
		} catch (\Throwable $e) {
			// Non-fatal: a re-run of the mail index writes source=mail itself.
			$output->warning('eva_ai: could not backfill the mail source: ' . $e->getMessage());
		}
	}
}
