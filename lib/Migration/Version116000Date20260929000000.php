<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Persist hashed, user-owned programmatic API credentials. */
final class Version116000Date20260929000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_api_keys')) {
			$table = $schema->createTable('eva_ai_api_keys');
			$table->addColumn('id', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 80, 'notnull' => true]);
			$table->addColumn('key_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('key_prefix', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('scope', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('ip_whitelist', Types::TEXT, ['notnull' => false]);
			$table->addColumn('last_used_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('call_count', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['key_hash'], 'eva_ai_api_key_hash');
			$table->addIndex(['user_id', 'created_at'], 'eva_ai_api_key_user');
		}
		return $schema;
	}
}
