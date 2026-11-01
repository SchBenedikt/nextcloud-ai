<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Store the bounded count of SQL vectors read for dense chat retrieval.
 *
 * The column name stays within 30 characters: Nextcloud rejects longer
 * identifiers on older supported releases (stable30 failed `occ app:enable`
 * with "Column name \"oc_eva_ai_usage\".\"retrieval_dense_sql_rows_scanned\" is
 * too long"), which blocked installing the app at all.
 */
final class Version118094Date20261029000021 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_usage')) return $schema;

		$table = $schema->getTable('eva_ai_usage');
		if (!$table->hasColumn('retrieval_dense_rows_scanned')) {
			$table->addColumn('retrieval_dense_rows_scanned', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		return $schema;
	}
}
