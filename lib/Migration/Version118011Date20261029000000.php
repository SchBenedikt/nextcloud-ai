<?php
declare(strict_types=1);
namespace OCA\EvaAi\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

/** Persist bounded verification progress; never contacts or activates storage. */
final class Version118011Date20261029000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if(!$schema->hasTable('eva_ai_vec_verifications')){
            $table=$schema->createTable('eva_ai_vec_verifications');
            foreach(['generation_id'=>32,'user_id'=>64,'owner_hash'=>64,'status'=>16] as$key=>$length)$table->addColumn($key,Types::STRING,['length'=>$length,'notnull'=>true]);
            foreach(['lease_token'=>32,'error_code'=>48,'verification_hash'=>64] as$key=>$length)$table->addColumn($key,Types::STRING,['length'=>$length,'notnull'=>false]);
            $table->addColumn('phase',Types::STRING,['length'=>12,'notnull'=>true,'default'=>'points']);
            foreach(['revision','after_chunk_id','after_document_id','upper_document_id','expected_count','checked_count','sample_count','mismatch_count','lease_until','heartbeat_at','created_at','updated_at'] as$key)
                $table->addColumn($key,Types::BIGINT,['notnull'=>true,'default'=>0]);
            $table->addColumn('remote_count',Types::BIGINT,['notnull'=>false]);
            $table->setPrimaryKey(['generation_id']);
            $table->addIndex(['status','lease_until','updated_at'],'eva_verify_claim');
            $table->addIndex(['user_id'],'eva_verify_owner');
        }
        return $schema;
    }
}
