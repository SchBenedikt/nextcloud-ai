<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keep cross-user recovery scans bounded as the dirty queue grows. */
final class Version118066Date20261029000013 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_dirty_index')) {
			$table = $schema->getTable('eva_ai_dirty_index');
			if (!$table->hasIndex('eva_dirty_recovery_due')) $table->addIndex(['status', 'available_at', 'user_id'], 'eva_dirty_recovery_due');
			if (!$table->hasIndex('eva_dirty_recovery_lease')) $table->addIndex(['status', 'lease_until', 'user_id'], 'eva_dirty_recovery_lease');
		}
		return $schema;
	}
}
