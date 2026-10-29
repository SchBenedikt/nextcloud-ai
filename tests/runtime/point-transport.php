<?php
declare(strict_types=1);

// Opt-in: isolated in-memory SQL plus a disposable loopback Qdrant instance.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
$root = getenv('NEXTCLOUD_ROOT'); $url = getenv('EVA_VECTOR_TEST_URL');
if (!$root || !is_file($root.'/occ') || !$url || !preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$url)) {
    throw new RuntimeException('Set NEXTCLOUD_ROOT and a disposable loopback EVA_VECTOR_TEST_URL.');
}
define('OC_CONSOLE',1); $_SERVER['SCRIPT_FILENAME']=$root.'/occ'; $_SERVER['SCRIPT_NAME']='/occ'; chdir($root);
require $root.'/lib/base.php';
$loader=require dirname(__DIR__,2).'/vendor/autoload.php';
$loader->addPsr4('OCA\\EvaAi\\',dirname(__DIR__,2).'/lib/',true); $loader->register(true);
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});

use OCA\EvaAi\Db\{Document,DocumentMapper,VectorOutboxRepository,VectorOwnerFenceRepository,VectorGenerationRepository,GenerationChunkRepository,VectorTargetRepository,VectorSourceDeletion};
use OCA\EvaAi\Vector\{VectorGeneration,VectorPointWorker,VectorSourceAccess,QdrantConnection,QdrantVectorStore,SearchFilters};
use OCA\EvaAi\Dto\PreparedVectorChunk;
use OCP\DB\Types;

final class PointTransportDoubles extends PHPUnit\Framework\TestCase {
    public function stub(string $class):object { return $this->createMock($class); }
    public function fixture():void {}
}
$doubles=new PointTransportDoubles('fixture');
$assert=static function(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);};
$db=OC::$server->get(OC\DB\ConnectionFactory::class)->getConnection('sqlite3',[
    'memory'=>true,'path'=>null,'tablePrefix'=>'vp_','user'=>'','password'=>'','sharding'=>[],
]);
$assert(($db->getParams()['memory']??false)===true && ($db->getParams()['path']??null)===null,'Refusing non-memory fixture database.');
$schema=new OC\DB\SchemaWrapper($db,new Doctrine\DBAL\Schema\Schema());
$table=$schema->createTable('appconfig');
foreach(['appid','configkey','configvalue'] as $c)$table->addColumn($c,Types::STRING,['length'=>4096,'notnull'=>true]);
$table->setPrimaryKey(['appid','configkey']);
$table=$schema->createTable('eva_ai_documents');
$table->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$table->setPrimaryKey(['id']);
foreach(['user_id','path','name','source','content_hash','mime'] as $c)$table->addColumn($c,Types::STRING,['length'=>4096,'notnull'=>true]+($c==='source'?['default'=>'files']:[]));
foreach(['file_id','file_mtime','size','chunk_count','indexed_at'] as $c)$table->addColumn($c,Types::BIGINT,['notnull'=>true,'default'=>0]);
$table=$schema->createTable('eva_ai_chunks');
$table->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$table->setPrimaryKey(['id']);
foreach(['document_id','chunk_index','token_count'] as $c)$table->addColumn($c,Types::BIGINT,['notnull'=>true,'default'=>0]);
foreach(['content','embedding','provenance'] as $c)$table->addColumn($c,Types::TEXT,['notnull'=>true]);
$output=$doubles->stub(OCP\Migration\IOutput::class);
foreach(['Version118000Date20261028000000','Version118001Date20261028000001','Version118002Date20261028000002','Version118003Date20261028000003','Version118004Date20261028000004','Version118010Date20261028000010'] as $class){
    $class='OCA\\EvaAi\\Migration\\'.$class;(new $class())->changeSchema($output,static fn()=>$schema,[]);
}
foreach($schema->getWrappedSchema()->toSql($db->getDatabasePlatform()) as $sql)$db->executeStatement($sql);
$inner=$db;
if (!$db instanceof OCP\IDBConnection) $db=new OC\DB\ConnectionAdapter($db);
$config=$doubles->stub(OCP\IConfig::class);
$config->method('getAppValue')->willReturnCallback(static function($app,$key,$default='')use($db):string{
    $q=$db->getQueryBuilder();$q->select('configvalue')->from('appconfig')->where($q->expr()->eq('appid',$q->createNamedParameter($app)))->andWhere($q->expr()->eq('configkey',$q->createNamedParameter($key)));
    $r=$q->executeQuery();try{$value=$r->fetchOne();return $value===false?$default:(string)$value;}finally{$r->closeCursor();}
});
$config->method('setAppValue')->willReturnCallback(static function($app,$key,$value)use($db):void{
    $db->insertIgnoreConflict('appconfig',['appid'=>$app,'configkey'=>$key,'configvalue'=>$value]);
    $q=$db->getQueryBuilder();$q->update('appconfig')->set('configvalue',$q->createNamedParameter($value))->where($q->expr()->eq('appid',$q->createNamedParameter($app)))->andWhere($q->expr()->eq('configkey',$q->createNamedParameter($key)))->executeStatement();
});
$config->method('getSystemValue')->willReturnCallback(static fn($key,$default='')=>$key==='allow_local_remote_servers'?true:$default);
$config->method('getSystemValueBool')->willReturnCallback(static fn($key,$default=false)=>$key==='allow_local_remote_servers'?true:$default);
$config->setAppValue('eva_ai','vector_private_origins',json_encode([$url]));
$connection=new QdrantConnection($config,OC::$server->get(OCP\Security\ICrypto::class),OC::$server->get(OCP\Http\Client\IClientService::class),new VectorTargetRepository($db));
$connection->configure($url,null,true,true);$target=$connection->targetFingerprint();
$store=new QdrantVectorStore($connection);
$journal=new VectorOutboxRepository($db);$owners=new VectorOwnerFenceRepository($db,$journal);
$generations=new VectorGenerationRepository($db,$journal,$owners);$chunks=new GenerationChunkRepository($db,$journal,$owners);
$deletion=new VectorSourceDeletion($db,$journal);$documents=new DocumentMapper($db,$deletion);
$access=$doubles->stub(VectorSourceAccess::class);$access->method('assertOwner')->willReturn($doubles->stub(OCP\IUser::class));
$access->method('assertAccessible')->willReturnCallback(static function()use($db,$assert):void{$assert(!$db->inTransaction(),'Point transfer guard ran inside a SQL transaction.');});
$worker=new VectorPointWorker($journal,$generations,$chunks,$access,$store,OC::$server->get(Psr\Log\LoggerInterface::class));
$created=[];
try {
    $entries=[];
    foreach(['alpha','beta'] as $index=>$owner){
        $g=new VectorGeneration(bin2hex(random_bytes(16)),'eva-vector-fixture-'.$owner,'qdrant','ollama',null,'fixture',hash('sha256','fixture'),3,900,120);
        $generations->create($g);$generations->bindTarget($g->userId,$g->id,$target);$store->forTarget($target)->prepare($g);$created[]=$g;
        $d=new Document();$d->setUserId($g->userId);$d->setFileId(100+$index);$d->setPath('Notes/'.$owner.'.txt');$d->setName($owner.'.txt');$d->setMime('text/plain');$d->setSource('files');$d->setFileMtime(1);$d->setSize(10);$d->setContentHash(md5($owner));$d=$documents->insert($d);
        $staged=$chunks->putBuildingChunk($g,$target,$chunks->snapshot($g->userId,$d->getId()),new PreparedVectorChunk(0,'fixture '.$owner,[0.6,0.8,0.0],4));
        $point=$chunks->pointForDelivery($g,$staged['id'],$staged['revision']);$entries[$owner]=compact('g','d','staged','point');
    }
    $assert($worker->run(2)['claimed']===0,'Point delivery was enabled by connection configuration.');
    $connection->setPointDelivery(true);
    $stats=$worker->run(2);$assert($stats['delivered']===2,'Initial point delivery failed: '.json_encode($stats));
    $alpha=$entries['alpha'];$beta=$entries['beta'];
    $q=$db->getQueryBuilder();$q->select('vector_state')->from('eva_ai_chunks')->where($q->expr()->eq('id',$q->createNamedParameter($alpha['staged']['id'],OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
    $r=$q->executeQuery();try{$assert($r->fetchOne()==='delivered','SQL acknowledgement was not revision-bound.');}finally{$r->closeCursor();}
    $hits=$store->search($alpha['g'],[0.6,0.8,0.0],10,new SearchFilters());
    $assert(count($hits)===1&&$hits[0]['chunkId']===$alpha['staged']['id'],'Tenant/generation query did not isolate alpha.');
    $documents->delete($alpha['d']);$stats=$worker->run(1);$assert($stats['delivered']===1,'Tombstone delivery failed.');
    // Simulate an already-in-flight write arriving after canonical deletion.
    $store->upsert($alpha['g'],[$alpha['point']],$alpha['staged']['revision']);
    $store->upsert($alpha['g'],[$alpha['point']],$alpha['staged']['revision']+1000);
    $assert($store->search($alpha['g'],[0.6,0.8,0.0],10,new SearchFilters())===[],'A delayed live write resurrected a tombstoned point.');
    $raw=$connection->request('post','/collections/'.$alpha['g']->collection().'/points',['ids'=>[$alpha['staged']['id']],'with_payload'=>true,'with_vector'=>true],false,10,$target);
    $payload=$raw['result'][0]['payload']??[];
    $assert(($payload['deleted']??false)===true&&!isset($payload['file_id'])&&!isset($payload['content_hash']),'Tombstone retained private point metadata.');
    $updated=$chunks->putBuildingChunk($beta['g'],$target,$chunks->snapshot($beta['g']->userId,$beta['d']->getId()),new PreparedVectorChunk(0,'fixture beta',[0.0,1.0,0.0],4));
    $expired=$journal->claimPoint(time(),60);
    $q=$db->getQueryBuilder();$q->update('eva_ai_vec_outbox')->set('lease_until',$q->createNamedParameter(time()-1,OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($expired['id'],OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
    $reclaimed=$journal->claimPoint(time(),60);
    $assert($reclaimed['id']===$expired['id']&&$reclaimed['lease_token']!==$expired['lease_token'],'Lease was not reclaimed with a fresh token.');
    $assert(!$journal->completePoint($expired,time()),'An old lease acknowledged its successor.');
    // Make the fixture successor eligible for the normal worker without waiting.
    $q=$db->getQueryBuilder();$q->update('eva_ai_vec_outbox')->set('state',$q->createNamedParameter('pending'))
        ->set('available_at',$q->createNamedParameter(0,OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
        ->where($q->expr()->eq('id',$q->createNamedParameter($reclaimed['id'],OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
    $assert($worker->run(1)['delivered']===1,'Changed revision did not deliver.');
    $store->upsert($beta['g'],[$beta['point']],$beta['staged']['revision']);
    $hits=$store->search($beta['g'],[0.0,1.0,0.0],10,new SearchFilters());
    $assert(count($hits)===1&&$hits[0]['revision']===$updated['revision'],'An old write overwrote a newer revision.');
    echo "PASS: real Qdrant 1.16 transport, isolated SQLite migrations/journal, explicit consent, tenant/generation queries, current revision, reclaimed leases and terminal tombstones.\n";
} finally {
    foreach($created as $g)$store->forTarget($target)->deleteGeneration($g);
    $inner->close();
}
echo "Disposable collections removed; live Nextcloud configuration and SQL data were not modified.\n";
