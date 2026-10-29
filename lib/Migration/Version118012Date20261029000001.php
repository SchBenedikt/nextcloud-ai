<?php
declare(strict_types=1);
namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Durable, coalesced source-change intent for active vector generations. */
final class Version118012Date20261029000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_vec_mutations')) {
            $table = $schema->createTable('eva_ai_vec_mutations');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            foreach (['generation_id'=>32, 'user_id'=>64, 'status'=>16] as $key => $length) {
                $table->addColumn($key, Types::STRING, ['length' => $length, 'notnull' => true]);
            }
            foreach (['lease_token'=>32, 'error_code'=>48] as $key => $length) {
                $table->addColumn($key, Types::STRING, ['length' => $length, 'notnull' => false]);
            }
            foreach (['document_id','revision','attempts','available_at','lease_until','created_at','updated_at'] as $key) {
                $table->addColumn($key, Types::BIGINT, ['notnull' => true, 'default' => 0]);
            }
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['generation_id','document_id'], 'eva_vec_mutation_source');
            $table->addIndex(['status','available_at','lease_until','id'], 'eva_vec_mutation_claim');
            $table->addIndex(['user_id','generation_id','status'], 'eva_vec_mutation_owner');
        }
        return $schema;
    }
}
