<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add the per-account SQL read feature flag without changing existing modes. */
final class Version118028Date20261029000005 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_chat_migrations')) {
			$table = $schema->getTable('eva_ai_chat_migrations');
			if (!$table->hasColumn('sql_read_enabled')) {
				$table->addColumn('sql_read_enabled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
		}
		return $schema;
	}
}
