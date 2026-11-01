<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Index the bounded lookup for reusable empty SQL chats. */
final class Version118080Date20261029000016 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('eva_ai_chats')) {
			$table = $schema->getTable('eva_ai_chats');
			if (!$table->hasIndex('eva_ai_chats_empty_page_idx')) {
				$table->addIndex(['user_id', 'message_count', 'updated_at', 'chat_id'], 'eva_ai_chats_empty_page_idx');
			}
		}
		return $schema;
	}
}
