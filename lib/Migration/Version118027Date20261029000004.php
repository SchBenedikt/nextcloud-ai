<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Additive encrypted chat tables; AppData remains the source until cutover. */
final class Version118027Date20261029000004 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('eva_ai_chats')) {
			$table = $schema->createTable('eva_ai_chats');
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('chat_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('message_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('encrypted_metadata', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['user_id', 'chat_id'], 'eva_ai_chats_pk');
			$table->addIndex(['user_id', 'updated_at', 'chat_id'], 'eva_ai_chats_page_idx');
		}

		if (!$schema->hasTable('eva_ai_chat_messages')) {
			$table = $schema->createTable('eva_ai_chat_messages');
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('chat_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('message_index', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('encrypted_message', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['user_id', 'chat_id', 'message_index'], 'eva_ai_chat_msg_pk');
			$table->addIndex(['user_id', 'chat_id', 'created_at'], 'eva_ai_chat_msg_time_idx');
		}

		if (!$schema->hasTable('eva_ai_chat_migrations')) {
			$table = $schema->createTable('eva_ai_chat_migrations');
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('cursor', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('source_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('error_code', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->setPrimaryKey(['user_id'], 'eva_ai_chat_migrations_pk');
			$table->addIndex(['state', 'updated_at'], 'eva_ai_chat_mig_state_idx');
		}

		return $schema;
	}
}
