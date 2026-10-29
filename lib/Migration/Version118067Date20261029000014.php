<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store bounded retrieval stage durations on existing chat usage rows. */
final class Version118067Date20261029000014 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_usage')) return $schema;
		$table = $schema->getTable('eva_ai_usage');
		if (!$table->hasColumn('retrieval_backend')) {
			$table->addColumn('retrieval_backend', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'none']);
		}
		if (!$table->hasColumn('retrieval_stage_mask')) {
			$table->addColumn('retrieval_stage_mask', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		foreach (['count', 'embedding', 'candidates', 'hydrate', 'scope', 'ranking', 'total'] as $stage) {
			$column = 'retrieval_' . $stage . '_ms';
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
		}
		return $schema;
	}
}
