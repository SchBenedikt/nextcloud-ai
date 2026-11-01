<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Index global duration-window reads used by the aggregate OpenMetrics exporter. */
final class Version118042Date20261029000009 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_usage')) {
			$table = $schema->getTable('eva_ai_usage');
			if (!$table->hasIndex('eva_ai_usage_op_time')) $table->addIndex(['operation', 'created_at'], 'eva_ai_usage_op_time');
		}
		return $schema;
	}
}
