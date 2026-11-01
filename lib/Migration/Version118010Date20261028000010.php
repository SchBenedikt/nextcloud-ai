<?php
declare(strict_types=1);
namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Durable build control/checkpoints only; installation starts no work. */
final class Version118010Date20261028000010 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('eva_ai_vec_builds')) {
            $table = $schema->createTable('eva_ai_vec_builds');
            foreach (['generation_id'=>32, 'user_id'=>64, 'owner_hash'=>64, 'target_hash'=>64, 'status'=>16] as $key=>$length) {
                $table->addColumn($key, Types::STRING, ['length'=>$length, 'notnull'=>true]);
            }
            foreach (['lease_token'=>32, 'plan_hash'=>64, 'error_code'=>48] as $key=>$length) {
                $table->addColumn($key, Types::STRING, ['length'=>$length, 'notnull'=>false]);
            }
            foreach (['revision','upper_document_id','after_document_id','document_id','chunk_cursor','documents_done','total_documents',
                'lease_until','heartbeat_at','created_at','updated_at'] as $key) {
                $table->addColumn($key, Types::BIGINT, ['notnull'=>true, 'default'=>0]);
            }
            $table->setPrimaryKey(['generation_id']);
            $table->addIndex(['status','lease_until','updated_at'], 'eva_build_claim');
            $table->addIndex(['user_id'], 'eva_build_owner');
        }
        if (!$schema->hasTable('eva_ai_vec_build_docs')) {
            $table = $schema->createTable('eva_ai_vec_build_docs');
            foreach (['generation_id'=>32, 'user_id'=>64, 'source_stamp'=>64, 'plan_hash'=>64] as $key=>$length) {
                $table->addColumn($key, Types::STRING, ['length'=>$length, 'notnull'=>true]);
            }
            foreach (['document_id','chunk_count'] as $key) $table->addColumn($key, Types::BIGINT, ['notnull'=>true]);
            $table->setPrimaryKey(['generation_id','document_id']);
            $table->addIndex(['user_id'], 'eva_build_doc_owner');
        }
        return $schema;
    }
}
