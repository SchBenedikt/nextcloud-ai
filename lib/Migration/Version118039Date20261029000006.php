<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Durable encrypted retry queue for failed account erasure steps. */
final class Version118039Date20261029000006 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_account_cleanup')) {
			$table = $schema->createTable('eva_ai_account_cleanup');
			$table->addColumn('owner_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('encrypted_user', Types::TEXT, ['notnull' => true]);
			$table->addColumn('attempts', Types::INTEGER, ['default' => 0, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('available_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('lease_token', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('lease_until', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('last_error', Types::STRING, ['length' => 48, 'notnull' => false]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['owner_hash']);
			$table->addIndex(['available_at', 'lease_until'], 'eva_ai_cleanup_due');
		}
		return $schema;
	}
}
