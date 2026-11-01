<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store privacy-safe indexing run counts for bounded performance metrics. */
final class Version118087Date20261029000018 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_usage')) return $schema;
		$table = $schema->getTable('eva_ai_usage');
		if (!$table->hasColumn('index_run_mode')) {
			$table->addColumn('index_run_mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'all']);
		}
		if (!$table->hasColumn('index_run_items')) {
			$table->addColumn('index_run_items', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('index_run_chunks')) {
			$table->addColumn('index_run_chunks', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('index_run_file_duration_ms')) {
			$table->addColumn('index_run_file_duration_ms', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		return $schema;
	}
}
