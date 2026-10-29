<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keep bounded usage-metric retention scans efficient. */
final class Version118043Date20261029000010 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_usage')) {
			$table = $schema->getTable('eva_ai_usage');
			if (!$table->hasIndex('eva_ai_usage_expiry')) $table->addIndex(['created_at', 'id'], 'eva_ai_usage_expiry');
		}
		return $schema;
	}
}
