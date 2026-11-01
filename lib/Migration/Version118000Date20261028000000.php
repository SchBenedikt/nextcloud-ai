<?php

declare(strict_types=1);

namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add metadata only. Never infer old vector provenance or contact a service. */
final class Version118000Date20261028000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_vec_generations')) {
            $table = $schema->createTable('eva_ai_vec_generations');
            foreach (['id' => 32, 'user_id' => 64, 'backend' => 16, 'collection_name' => 64,
                'embedding_provider' => 32, 'embedding_model' => 128, 'endpoint_hash' => 64,
                'distance_metric' => 16, 'status' => 16] as $name => $length) {
                $table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => true]);
            }
            $table->addColumn('profile_id', Types::STRING, ['length' => 32, 'notnull' => false]);
            foreach (['dimension', 'chunk_size', 'chunk_overlap', 'schema_version'] as $name) {
                $table->addColumn($name, Types::INTEGER, ['notnull' => true]);
            }
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('activated_at', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('verified_at', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('verification_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addColumn('build_cursor', Types::BIGINT, ['default' => 0, 'notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['collection_name'], 'eva_vec_collection');
            $table->addIndex(['user_id', 'status'], 'eva_vec_user_status');
        }
        if (!$schema->hasTable('eva_ai_vec_state')) {
            $table = $schema->createTable('eva_ai_vec_state');
            $table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('active_generation_id', Types::STRING, ['length' => 32, 'notnull' => false]);
            $table->addColumn('retrieval_backend', Types::STRING, ['length' => 16, 'default' => 'sql_legacy', 'notnull' => true]);
            $table->setPrimaryKey(['user_id']);
        }
        if ($schema->hasTable('eva_ai_chunks')) {
            $table = $schema->getTable('eva_ai_chunks');
            if (!$table->hasColumn('vector_generation_id')) $table->addColumn('vector_generation_id', Types::STRING, ['length' => 32, 'notnull' => false]);
            if (!$table->hasColumn('vector_state')) $table->addColumn('vector_state', Types::STRING, ['length' => 16, 'default' => 'legacy', 'notnull' => true]);
            if (!$table->hasColumn('content_hash')) $table->addColumn('content_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
            if (!$table->hasIndex('eva_chunk_generation')) $table->addIndex(['vector_generation_id', 'vector_state', 'id'], 'eva_chunk_generation');
        }
        return $schema;
    }
}
