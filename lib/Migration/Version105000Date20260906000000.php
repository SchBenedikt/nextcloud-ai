<?php

declare(strict_types=1);
namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version105000Date20260906000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_chat_items')) {
            $t = $schema->createTable('eva_ai_chat_items');
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('kind', 'string', ['length' => 16, 'notnull' => true]);
            $t->addColumn('item_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('title', 'string', ['length' => 255, 'notnull' => true, 'default' => '']);
            $t->addColumn('project_id', 'string', ['length' => 64, 'notnull' => true, 'default' => '']);
            $t->addColumn('archived', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('pinned', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('data', 'text', ['notnull' => true]);
            $t->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $t->setPrimaryKey(['user_id', 'kind', 'item_id'], 'eva_ai_chat_item_pk');
            $t->addIndex(['user_id', 'kind', 'updated_at'], 'eva_ai_chat_user_time');
        }
        if (!$schema->hasTable('eva_ai_messages')) {
            $t = $schema->createTable('eva_ai_messages');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('chat_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('role', 'string', ['length' => 16, 'notnull' => true]);
            $t->addColumn('content', 'text', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'chat_id', 'id'], 'eva_ai_message_user_chat');
        }
        if ($schema->hasTable('eva_ai_chunks') && !$schema->getTable('eva_ai_chunks')->hasColumn('provenance')) {
            $schema->getTable('eva_ai_chunks')->addColumn('provenance', 'text', ['notnull' => false]);
        }
        return $schema;
    }
}
