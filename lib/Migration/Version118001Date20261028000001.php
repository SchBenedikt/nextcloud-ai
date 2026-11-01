<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Durable target identity and synchronization journal; no remote I/O. */
final class Version118001Date20261028000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_vec_targets')) {
            $table = $schema->createTable('eva_ai_vec_targets');
            $table->addColumn('id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('endpoint', Types::TEXT, ['notnull' => true]);
            $table->addColumn('auth', Types::STRING, ['length' => 16, 'notnull' => true]);
            $table->addColumn('encrypted_key', Types::TEXT, ['notnull' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
        }
        if (!$schema->hasTable('eva_ai_vec_outbox')) {
            $table = $schema->createTable('eva_ai_vec_outbox');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            foreach (['user_id' => 64, 'generation_id' => 32, 'target_hash' => 64, 'operation' => 24, 'state' => 16] as $name => $length) {
                $table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => true]);
            }
            $table->addColumn('generation_json', Types::TEXT, ['notnull' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('chunk_id', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('attempts', Types::INTEGER, ['default' => 0, 'notnull' => true]);
            foreach (['created_at', 'available_at'] as $name) $table->addColumn($name, Types::BIGINT, ['notnull' => true]);
            foreach (['lease_until', 'delivered_at'] as $name) $table->addColumn($name, Types::BIGINT, ['notnull' => false]);
            $table->addColumn('lease_token', Types::STRING, ['length' => 32, 'notnull' => false]);
            $table->addColumn('last_error', Types::STRING, ['length' => 48, 'notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['state', 'available_at', 'id'], 'eva_vec_outbox_due');
            $table->addIndex(['state', 'lease_until'], 'eva_vec_outbox_lease');
            $table->addIndex(['generation_id', 'id'], 'eva_vec_outbox_gen');
        }
        if ($schema->hasTable('eva_ai_vec_generations')) {
            $table = $schema->getTable('eva_ai_vec_generations');
            if (!$table->hasColumn('storage_endpoint_hash')) $table->addColumn('storage_endpoint_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
        if ($schema->hasTable('eva_ai_chunks')) {
            $table = $schema->getTable('eva_ai_chunks');
            if (!$table->hasColumn('vector_revision')) $table->addColumn('vector_revision', Types::BIGINT, ['default' => 0, 'notnull' => true]);
        }
        return $schema;
    }
}
