<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store coalesced file-change work in Nextcloud SQL with recoverable leases. */
final class Version118065Date20261029000012 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('eva_ai_dirty_index')) {
			$table = $schema->createTable('eva_ai_dirty_index');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('event_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('old_path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('available_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('lease_until', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'event_key'], 'eva_dirty_owner_event');
			$table->addIndex(['user_id', 'status', 'available_at', 'id'], 'eva_dirty_due');
			$table->addIndex(['user_id', 'status', 'lease_until'], 'eva_dirty_lease');
		}
		if (!$schema->hasTable('eva_ai_dirty_meta')) {
			$table = $schema->createTable('eva_ai_dirty_meta');
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('legacy_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('legacy_offset', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['user_id']);
		}
		return $schema;
	}
}
