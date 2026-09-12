<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store privacy-preserving model usage aggregates for the user's metrics page. */
final class Version110000Date20260912000001 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_usage')) {
			$table = $schema->createTable('eva_ai_usage');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('provider', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('model', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('operation', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('input_tokens', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('output_tokens', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('total_tokens', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('estimated', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('duration_ms', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'created_at'], 'eva_ai_usage_user_time');
			$table->addIndex(['user_id', 'provider', 'model'], 'eva_ai_usage_user_model');
		}
		return $schema;
	}
}
