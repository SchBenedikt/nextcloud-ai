<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version103000Date20260810000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('eva_ai_agent_state')) {
			$table = $schema->createTable('eva_ai_agent_state');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
				'default' => '',
			]);
			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 128,
				'default' => '',
			]);
			$table->addColumn('history', Types::TEXT, ['notnull' => false]);
			$table->addColumn('pending', Types::TEXT, ['notnull' => false]);
			$table->addColumn('updated_at', Types::INTEGER, [
				'notnull' => true,
				'length' => 8,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'token'], 'eva_agent_uid_token');
			$table->addIndex(['user_id'], 'eva_agent_uid');
		}

		return $schema;
	}
}
