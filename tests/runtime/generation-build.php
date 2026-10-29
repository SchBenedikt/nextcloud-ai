<?php
declare(strict_types=1);
// Isolated native SQLite migrations/repositories; no real users, keys or service requests.
$root=getenv('NEXTCLOUD_ROOT');
if(PHP_SAPI!=='cli'||getenv('EVA_BUILD_SQL_FIXTURE')!=='1'||!$root||!is_file($root.'/occ'))throw new RuntimeException('Set NEXTCLOUD_ROOT and EVA_BUILD_SQL_FIXTURE=1.');
define('OC_CONSOLE',1); $_SERVER['SCRIPT_FILENAME']=$root.'/occ'; $_SERVER['SCRIPT_NAME']='/occ'; chdir($root);
require $root.'/lib/base.php';
// In a real Nextcloud installation the app's Composer loader is already
// registered by base.php. Requiring a second generated Composer loader would
// redeclare its global initializer class; isolated install fixtures still need
// the repository loader when the app is not installed in NEXTCLOUD_ROOT.
if (!class_exists(\OCA\EvaAi\Db\GenerationBuildRepository::class)) {
    $loader=require dirname(__DIR__,2).'/vendor/autoload.php';
    $loader->addPsr4('OCA\\EvaAi\\',dirname(__DIR__,2).'/lib/',true); $loader->register(true);
}
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});

use OCA\EvaAi\Db\{Document,DocumentMapper,VectorOutboxRepository,VectorOwnerFenceRepository,VectorGenerationRepository,GenerationChunkRepository,VectorTargetRepository,VectorSourceDeletion};
use OCA\EvaAi\Vector\{VectorGeneration,VectorPointWorker,VectorSourceAccess,QdrantConnection,QdrantVectorStore,SearchFilters};
use OCA\EvaAi\Dto\{PreparedVectorChunk,PreparedGenerationDocument};
use OCA\EvaAi\Db\GenerationBuildRepository;
use OCA\EvaAi\Vector\{GenerationBuildWorker,GenerationDocumentPreparer,GenerationEmbeddingProvider};
use OCA\EvaAi\Service\AppConfig;
use OCP\DB\Types;

final class BuildFixtureDoubles extends PHPUnit\Framework\TestCase {
    public function stub(string $class):object { return $this->createMock($class); }
    public function fixture():void {}
}
$doubles=new BuildFixtureDoubles('fixture');
$assert=static function(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);};
$db=OC::$server->get(OC\DB\ConnectionFactory::class)->getConnection('sqlite3',[
    'memory'=>true,'path'=>null,'tablePrefix'=>'gb_','user'=>'','password'=>'','sharding'=>[],
]);
$assert(($db->getParams()['memory']??false)===true && ($db->getParams()['path']??null)===null,'Refusing non-memory fixture database.');
$schema=new OC\DB\SchemaWrapper($db,new Doctrine\DBAL\Schema\Schema());
$table=$schema->createTable('appconfig');
foreach(['appid','configkey','configvalue'] as $c)$table->addColumn($c,Types::STRING,['length'=>4096,'notnull'=>true]);
$table->setPrimaryKey(['appid','configkey']);
$table=$schema->createTable('preferences');
foreach(['userid','appid','configkey','configvalue'] as$c)$table->addColumn($c,Types::STRING,['length'=>4096,'notnull'=>true]);
$table->setPrimaryKey(['userid','appid','configkey']);
$table=$schema->createTable('eva_ai_documents');
$table->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$table->setPrimaryKey(['id']);
foreach(['user_id','path','name','source','content_hash','mime'] as $c)$table->addColumn($c,Types::STRING,['length'=>4096,'notnull'=>true]+($c==='source'?['default'=>'files']:[]));
foreach(['file_id','file_mtime','size','chunk_count','indexed_at'] as $c)$table->addColumn($c,Types::BIGINT,['notnull'=>true,'default'=>0]);
$table=$schema->createTable('eva_ai_chunks');
$table->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$table->setPrimaryKey(['id']);
foreach(['document_id','chunk_index','token_count'] as $c)$table->addColumn($c,Types::BIGINT,['notnull'=>true,'default'=>0]);
foreach(['content','embedding','provenance'] as $c)$table->addColumn($c,Types::TEXT,['notnull'=>true]);
$output=$doubles->stub(OCP\Migration\IOutput::class);
foreach(['Version118000Date20261028000000','Version118001Date20261028000001','Version118002Date20261028000002','Version118003Date20261028000003','Version118004Date20261028000004','Version118010Date20261028000010','Version118011Date20261029000000','Version118012Date20261029000001','Version118026Date20261029000003','Version118040Date20261029000007','Version118041Date20261029000008'] as $class){
    $class='OCA\\EvaAi\\Migration\\'.$class;(new $class())->changeSchema($output,static fn()=>$schema,[]);
}
foreach($schema->getWrappedSchema()->toSql($db->getDatabasePlatform()) as $sql)$db->executeStatement($sql);
$inner=$db;
if (!$db instanceof OCP\IDBConnection) $db=new OC\DB\ConnectionAdapter($db);
$loadedRepository=realpath((new ReflectionClass(GenerationBuildRepository::class))->getFileName());
$candidateRepository=realpath(dirname(__DIR__,2).'/lib/Db/GenerationBuildRepository.php');
$installedRepository=realpath($root.'/apps/eva_ai/lib/Db/GenerationBuildRepository.php');
$assert($loadedRepository===$candidateRepository||($installedRepository!==false&&$loadedRepository===$installedRepository),
    'Wrong candidate or installed build repository loaded.');
$put=static function(string $key,string $value)use($db):void{
    $db->insertIgnoreConflict('appconfig',['appid'=>'eva_ai','configkey'=>$key,'configvalue'=>$value]);
    $q=$db->getQueryBuilder();$q->update('appconfig')->set('configvalue',$q->createNamedParameter($value))
        ->where($q->expr()->eq('appid',$q->createNamedParameter('eva_ai')))->andWhere($q->expr()->eq('configkey',$q->createNamedParameter($key)))->executeStatement();
};
$url='https://vector-fixture.invalid';$target=hash('sha256',$url);
foreach(['qdrant_enabled'=>'1','vector_sync_enabled'=>'1','vector_points_enabled'=>'1','qdrant_url'=>$url,'qdrant_key_target'=>$target] as$key=>$value)$put($key,$value);
$journal=new VectorOutboxRepository($db);$owners=new VectorOwnerFenceRepository($db,$journal);
$generations=new VectorGenerationRepository($db,$journal,$owners);$chunks=new GenerationChunkRepository($db,$journal,$owners);
$deletion=new VectorSourceDeletion($db,$journal);$documents=new DocumentMapper($db,$deletion);
$builds=new GenerationBuildRepository($db,$journal,$owners,$generations);
$owner='admin';$config=$doubles->stub(AppConfig::class);
$config->method('userId')->willReturnCallback(static function()use(&$owner){return $owner;});
$config->method('setUserId')->willReturnCallback(static function($id)use(&$owner):void{$owner=$id;});
$texts=[];
$makeDoc=static function(string $user,int $file,string $text)use($documents,&$texts):Document{
    $d=new Document();$d->setUserId($user);$d->setFileId($file);$d->setPath('Notes/source-'.$file.'.txt');$d->setName('source.txt');$d->setSource('files');$d->setMime('text/plain');$d->setSize(strlen($text));$d->setFileMtime(1);$d->setContentHash(md5($text));$d=$documents->insert($d);$texts[$d->getId()]=$text;return $d;
};
$makeGen=static function(string $user,string $destination)use($generations):VectorGeneration{
    $g=new VectorGeneration(bin2hex(random_bytes(16)),$user,'qdrant','ollama',null,'fixture',hash('sha256','fixture'),3,128,12);
    $generations->create($g);$generations->bindTarget($user,$g->id,$destination);return $g;
};
$a=$makeDoc('alice',1,str_repeat('Public source sentence. ',150));$a2=$makeDoc('alice',2,'Another public source.');$b=$makeDoc('bob',3,'Another tenant.');
$g=$makeGen('alice',$target);$other=$makeGen('alice',$target);
$chunks->putBuildingChunk($other,$target,$chunks->snapshot('alice',(int)$a->getId()),new PreparedVectorChunk(0,'Other generation',[1,0,0],4));
$legacy=new OCA\EvaAi\Db\Chunk();$legacy->setDocumentId($a->getId());$legacy->setChunkIndex(0);$legacy->setContent('Legacy source');$legacy->setEmbeddingArray([1,0,0]);$legacy->setTokenCount(4);$legacy->setProvenanceArray([]);(new OCA\EvaAi\Db\ChunkMapper($db,$deletion))->insert($legacy);
$sources=$doubles->stub(OCA\EvaAi\Vector\GenerationSourceReader::class);
$sources->method('read')->willReturnCallback(static function($source)use(&$texts,&$mode,$db,$assert):string{$assert(!$db->inTransaction(),'Extraction ran under a SQL transaction');if($mode==='source-budget')throw new OCA\EvaAi\Vector\VectorStoreException('build_budget');if($mode==='source-changed-once'){$mode='success';throw new OCA\EvaAi\Vector\VectorStoreException('invalid_source_snapshot');}return $texts[$source->id];});
$preparer=new GenerationDocumentPreparer($sources,new OCA\EvaAi\Service\Chunker($config));
$access=$doubles->stub(VectorSourceAccess::class);$access->method('assertOwner')->willReturn($doubles->stub(OCP\IUser::class));
$access->method('assertAccessible')->willReturnCallback(static function()use($db,$assert):void{$assert(!$db->inTransaction(),'Native source I/O ran under a SQL transaction');});
$mode='pause-first';$calls=0;$embeddings=$doubles->stub(GenerationEmbeddingProvider::class);
$embeddings->method('embed')->willReturnCallback(static function($generation,$input,$guard,$cache,$timeout)use(&$mode,&$calls,$builds,$generations,$documents,$deletion,$db,$assert):array{
    $assert(!$db->inTransaction(),'Embedding ran under a SQL transaction');
    if($mode==='owner-pause')$db->insertIgnoreConflict('preferences',['userid'=>$generation->userId,'appid'=>'eva_ai','configkey'=>'index_enrolled','configvalue'=>'0']);
    $guard();$calls++;
    if($mode==='fail')throw new OCA\EvaAi\Vector\VectorStoreException('embedding_connection_failed');
    if(($mode==='pause-first'&&$calls===1)||($mode==='pause-second'&&$calls===2))$builds->control($generation->userId,$generation->id,'pause');
    if($mode==='erase')$generations->deleteUser($generation->userId,static function()use($documents,$generation):void{$documents->deleteByUser($generation->userId);});
    return array_fill(0,count($input),[1.0,0.0,0.0]);
});
$worker=new GenerationBuildWorker($builds,$generations,$chunks,$deletion,$preparer,$embeddings,$access,$config);
try {
    $builds->start('alice',$g->id,$target);$builds->start('alice',$g->id,$target);
    $assert($builds->find('alice',$g->id)['total_documents']===2,'Start counted another tenant or duplicated the job');
    $first=$worker->run();$assert($first['chunks']===0&&$builds->find('alice',$g->id)['status']==='paused','A paused in-flight embedding published chunks');
    $assert($builds->stagedCount($g->id)===0&&$owner==='admin','Pause leaked staged rows or config scope');
    $db->insertIgnoreConflict('preferences',['userid'=>'alice','appid'=>'eva_ai','configkey'=>'index_enrolled','configvalue'=>'0']);
    $assert(!$builds->indexAllowed('alice')&&!$builds->available('alice',$g->id,$target),'Personal index opt-out was ignored');
    try{$builds->control('alice',$g->id,'resume');throw new RuntimeException('Opted-out owner resumed');}catch(OCA\EvaAi\Vector\VectorStoreException $e){$assert($e->reason==='build_disabled','Wrong opted-out resume result');}
    $q=$db->getQueryBuilder();$q->delete('preferences')->where($q->expr()->eq('userid',$q->createNamedParameter('alice')))->executeStatement();
    $builds->control('alice',$g->id,'resume');$mode='pause-second';$calls=0;$second=$worker->run();
    $assert($second['chunks']===8&&$builds->find('alice',$g->id)['chunk_cursor']===8,'Partial batch checkpoint was lost on pause');
    $assert($builds->stagedCount($other->id)===1,'Build reset removed another generation');
    $builds->control('alice',$g->id,'resume');$old=$builds->claim(time());$assert($old!==null&&$builds->claim(time())===null,'A live lease was double-claimed');
    $q=$db->getQueryBuilder();$q->update('eva_ai_vec_builds')->set('lease_until',$q->createNamedParameter(time()-1,OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))->where($q->expr()->eq('generation_id',$q->createNamedParameter($g->id)))->executeStatement();
    $successor=$builds->claim(time());$assert($successor!==null&&$successor['lease_token']!==$old['lease_token'],'Expired lease did not get a fresh token');
    try{$builds->withLease($old,static fn()=>null);throw new RuntimeException('Old process checkpointed successor');}catch(OCA\EvaAi\Vector\VectorStoreException $e){$assert($e->reason==='stale_build_lease','Wrong old-lease failure');}
    $builds->release($old,'old_process');$assert($builds->find('alice',$g->id)['lease_token']===$successor['lease_token'],'Old process overwrote successor');
    // A metadata/content update invalidates old points behind a saved chunk cursor.
    $changed='Short changed canonical source.';$texts[$a->getId()]=$changed;$a->setSize(strlen($changed));$a->setContentHash(md5($changed));$documents->update($a);
    $builds->release($successor);$mode='success';$calls=0;$complete=$worker->run();$state=$builds->find('alice',$g->id);
    $assert($complete['staged']&&$state['documents_done']===2&&$state['status']==='staged','Build did not reach staged state');
    $assert($generations->status('alice',$g->id)==='building','Build activated an unverified generation');
    $legacyRows=(new OCA\EvaAi\Db\ChunkMapper($db,$deletion))->chunksForUser('alice');$assert(count($legacyRows)===1,'Legacy SQL index was modified');
    $q=$db->getQueryBuilder();$q->select('document_id')->from('eva_ai_vec_build_docs')->where($q->expr()->eq('generation_id',$q->createNamedParameter($g->id)));$r=$q->executeQuery();$ids=array_map('intval',array_column($r->fetchAll(),'document_id'));$r->closeCursor();sort($ids);
    $assert($ids===[(int)$a->getId(),(int)$a2->getId()],'Build manifest is incomplete or crossed tenant ownership');
    $export=$builds->exportMetadata('alice');$assert(count($export['builds'])===1&&count($export['documents'])===2,'Personal build export lost owned metadata');
    $assert(!str_contains(json_encode($export),'lease_token')&&!str_contains(json_encode($export),'target_hash'),'Personal export exposed runtime lease/target data');
    $changedSource=$makeGen('alice',$target);$builds->start('alice',$changedSource->id,$target);$mode='source-changed-once';
    $deferred=$worker->run();$deferredState=$builds->find('alice',$changedSource->id);
    $assert($deferred['deferred_for_source_refresh']&&$deferred['error']===null&&$deferredState['status']==='queued'&&$deferredState['error_code']==='source_changed',
        'A stale source was not deferred for the ordinary indexer refresh');
    $tooSoon=$worker->run();$assert(!$tooSoon['claimed'],'A stale source was retried before the indexer refresh window');
    $refreshed='Current source snapshot after the ordinary index pass.';$texts[$a->getId()]=$refreshed;
    $a->setSize(strlen($refreshed));$a->setContentHash(md5($refreshed));$documents->update($a);
    $refreshedState=$builds->find('alice',$changedSource->id);
    $assert($refreshedState['status']==='queued'&&$refreshedState['error_code']===null,'Canonical source refresh did not release the deferred build');
    $mode='success';$recovered=$worker->run();
    for($i=0;$i<4&&$recovered['claimed'];$i++)$recovered=$worker->run();
    $assert($builds->find('alice',$changedSource->id)['status']==='staged'&&$builds->find('alice',$g->id)['status']==='staged',
        'The refreshed source did not let all affected staged builds recover: '.json_encode($recovered));
    $gb=$makeGen('bob',$target);$builds->start('bob',$gb->id,$target);$mode='erase';$erased=$worker->run();
    $assert($builds->find('bob',$gb->id)===null&&$builds->stagedCount($gb->id)===0&&$documents->countForUser('bob')===0,'In-flight erasure resurrected a build/source');
    $retry=$makeGen('alice',$target);$builds->start('alice',$retry->id,$target);$mode='fail';$worker->run();
    $assert($builds->find('alice',$retry->id)['status']==='failed','Provider failure was hidden');
    $put('vector_points_enabled','0');$builds->control('alice',$retry->id,'pause');$assert($builds->claim(time())===null,'Disabled worker claimed work');
    $put('vector_points_enabled','1');$builds->control('alice',$retry->id,'resume');$builds->control('alice',$retry->id,'cancel');$assert($builds->claim(time())===null,'Cancelled build was claimed');
    $builds->control('alice',$retry->id,'resume');$lease=$builds->claim(time());
    try{$builds->withLease($lease,function()use($chunks,$retry,$target,$a,$builds,$lease):void{
        $source=$chunks->snapshot('alice',(int)$a->getId());$chunks->putBuildingChunk($retry,$target,$source,new PreparedVectorChunk(0,'Rollback source',[1,0,0],4));
        $builds->checkpoint($lease,['chunk_cursor'=>1]);throw new RuntimeException('Injected transaction failure');
    });throw new RuntimeException('Injected failure did not propagate');}catch(RuntimeException $e){$assert($e->getMessage()==='Injected transaction failure','Unexpected rollback failure');}
    $assert($builds->stagedCount($retry->id)===0&&$builds->find('alice',$retry->id)['chunk_cursor']===0,'SQL chunk/journal/checkpoint did not roll back together');
    $builds->release($lease);$put('qdrant_key_target',str_repeat('f',64));$assert($builds->claim(time())===null,'Changed target was claimed');
    $assert($builds->find('alice',$retry->id)['status']==='failed','Stale destination can starve the queue');
    $put('qdrant_key_target',$target);$personal=$makeGen('alice',$target);$builds->start('alice',$personal->id,$target);$mode='owner-pause';$calls=0;$worker->run();
    $personalState=$builds->find('alice',$personal->id);$assert($calls===0&&$personalState['error_code']==='owner_index_paused'&&$builds->stagedCount($personal->id)===0,'Fresh personal opt-out did not stop transfer/checkpoint');
    $q=$db->getQueryBuilder();$q->delete('preferences')->where($q->expr()->eq('userid',$q->createNamedParameter('alice')))->executeStatement();
    $budget=$makeGen('alice',$target);$builds->start('alice',$budget->id,$target);$mode='source-budget';$worker->run();
    $budgetState=$builds->find('alice',$budget->id);$assert($budgetState['status']==='failed'&&$budgetState['error_code']==='source_extraction_failed'&&$budgetState['document_id']>0,'No-progress source budget loops without an actionable durable checkpoint');
    $httpUrl=getenv('EVA_BUILD_HTTP_FIXTURE_URL');
    if($httpUrl){
        $uid=getenv('EVA_BUILD_NATIVE_TEST_USER');
        $assert(preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$httpUrl)===1&&$uid&&str_starts_with($uid,'eva-runtime-audit-'),'Use a dedicated loopback fixture and fresh native audit account.');
        $users=OC::$server->get(OCP\IUserManager::class);
        $assert(!$users->userExists($uid),'Never touch an existing account in this fixture.');
        $assert(OC::$server->get(VectorGenerationRepository::class)->ownerStatus($uid)==='uninitialized','Use a fresh account identity without a prior erasure fence.');
        $nativeUser=$users->createUser($uid,bin2hex(random_bytes(24)));
        $assert($nativeUser!==false&&$nativeUser!==null,'Native fixture account creation failed.');
        try {
            $put('qdrant_key_target',$target);$put('ollama_url',$httpUrl);
            $nativeFiles=OC::$server->get(OCP\Files\IRootFolder::class);$home=$nativeFiles->getUserFolder($uid);
            $text=str_repeat('Public build fixture sentence. ',20);
            $file=$home->newFile('Public build fixture.txt',trim($text));
            $nativeMail=OC::$server->get(OCA\EvaAi\Service\EmailService::class);
            $nativeAccess=new VectorSourceAccess($users,OC::$server->get(OCP\App\IAppManager::class),$nativeFiles,$nativeMail,OC::$server->get(OCA\EvaAi\Service\TalkTranscriptService::class));
            $config->method('getInt')->willReturnCallback(static fn($key,$default)=>$default);
            $policyConfig=$doubles->stub(OCP\IConfig::class);
            $policyConfig->method('getAppValue')->willReturnCallback(static fn($app,$key,$default='')=>$key==='provider_private_origins'?json_encode([$httpUrl]):$default);
            $policyConfig->method('getSystemValue')->willReturnCallback(static fn($key,$default='')=>$default);
            $config->method('providerEndpointPolicy')->willReturn(new OCA\EvaAi\Service\ProviderEndpointPolicy($policyConfig));
            $canonicalText=new OCA\EvaAi\Service\IndexDocumentText(new OCA\EvaAi\Service\IndexTextExtractor($config,OC::$server->get(Psr\Log\LoggerInterface::class)),$nativeMail);
            $canonical=$canonicalText->file($file);
            $nativeDoc=new Document();$nativeDoc->setUserId($uid);$nativeDoc->setFileId($file->getId());$nativeDoc->setPath($file->getName());$nativeDoc->setName($file->getName());$nativeDoc->setSource('files');$nativeDoc->setMime('text/plain');$nativeDoc->setSize($file->getSize());$nativeDoc->setFileMtime($file->getMTime());$nativeDoc->setContentHash(md5($canonical));$nativeDoc=$documents->insert($nativeDoc);
            $nativeGeneration=new VectorGeneration(bin2hex(random_bytes(16)),$uid,'qdrant','ollama',null,'fixture-embed',hash('sha256',$httpUrl),3,128,12);
            $generations->create($nativeGeneration);$generations->bindTarget($uid,$nativeGeneration->id,$target);
            $cacheBackend=$doubles->stub(OCP\ICache::class);$cacheBackend->method('get')->willReturn(null);$cacheBackend->method('set')->willReturn(true);
            $cacheFactory=$doubles->stub(OCP\ICacheFactory::class);$cacheFactory->method('createDistributed')->willReturn($cacheBackend);
            $embeddingProvider=new GenerationEmbeddingProvider($config,OC::$server->get(OCP\Http\Client\IClientService::class),
                new OCA\EvaAi\Vector\GenerationEmbeddingConfiguration($db,OC::$server->get(OCP\Security\ICrypto::class)),
                new OCA\EvaAi\Service\EmbeddingCache($cacheFactory,$config,OC::$server->get(Psr\Log\LoggerInterface::class)));
            $nativeSource=new OCA\EvaAi\Vector\GenerationSourceReader($config,$nativeAccess,$nativeFiles,$canonicalText,$nativeMail,OC::$server->get(OCA\EvaAi\Service\TalkTranscriptService::class));
            $nativeWorker=new GenerationBuildWorker($builds,$generations,$chunks,$deletion,new GenerationDocumentPreparer($nativeSource,new OCA\EvaAi\Service\Chunker($config)),$embeddingProvider,$nativeAccess,$config);
            $builds->start($uid,$nativeGeneration->id,$target);$result=$nativeWorker->run();
            $assert($result['staged']&&$result['error']===null&&$result['chunks']>0,'Native VFS + HTTP build failed: '.json_encode($result));
            $assert($builds->find($uid,$nativeGeneration->id)['documents_done']===1,'Native source did not checkpoint its manifest');
            $q=$db->getQueryBuilder();$q->select('id','vector_revision')->from('eva_ai_chunks')->where($q->expr()->eq('vector_generation_id',$q->createNamedParameter($nativeGeneration->id)))->setMaxResults(1);$r=$q->executeQuery();$row=$r->fetch();$r->closeCursor();
            $assert($row&&$chunks->pointForDelivery($nativeGeneration,(int)$row['id'],(int)$row['vector_revision'])!==null,'Real HTTP vectors were not stored at a deliverable canonical revision');
            echo "PASS: combined real native VFS/access/source extraction, loopback embedding HTTP and isolated SQL build/checkpoint/journal. No Qdrant transfer or live app flag change.\n";
        } finally {$assert($nativeUser->delete(),'Native disposable account deletion failed.');}
        $assert(!$users->userExists($uid)&&OC::$server->get(DocumentMapper::class)->countForUser($uid)===0,'Native source account cleanup retained ownership');
    }
    echo "PASS: native SQLite migrations, owned keyset build, explicit flags, pause after inference, partial resume, lease expiry/reclaim, stale-process rejection, atomic rollback, account erasure, cancellation, target change and legacy/tenant isolation.\n";
} finally {$inner->close();}
echo "Isolated SQL scenarios complete. The optional HTTP/VFS scenario uses only its disposable native account and loopback fixture; no live flag or vector collection is changed.\n";
