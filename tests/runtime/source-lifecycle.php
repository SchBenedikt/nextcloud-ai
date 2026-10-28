<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
$testUser = getenv('EVA_TEST_USER');
if (!$nextcloudRoot || !is_file($nextcloudRoot . '/occ') || !$testUser || !str_starts_with($testUser, 'eva-runtime-audit-')) {
    throw new RuntimeException('Set NEXTCLOUD_ROOT and a dedicated EVA_TEST_USER (eva-runtime-audit-*).');
}
define('OC_CONSOLE', 1);
$_SERVER['SCRIPT_FILENAME'] = $nextcloudRoot . '/occ';
$_SERVER['SCRIPT_NAME'] = '/occ';
chdir($nextcloudRoot);
require $nextcloudRoot . '/lib/base.php';
$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
$loader->addPsr4('OCA\\EvaAi\\', dirname(__DIR__, 2) . '/lib/', true);
$loader->register(true);
set_exception_handler(static function(Throwable $e): void {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
});
use OCA\EvaAi\Db\{Document,Chunk,DocumentMapper,ChunkMapper,VectorOutboxRepository,VectorOwnerFenceRepository,VectorGenerationRepository,GenerationChunkRepository,VectorSourceDeletion};
use OCA\EvaAi\Vector\VectorGeneration;
use OCA\EvaAi\Dto\PreparedVectorChunk;
$db=OC::$server->get(OCP\IDBConnection::class);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$assert(realpath((new ReflectionClass(VectorSourceDeletion::class))->getFileName())===realpath(dirname(__DIR__, 2).'/lib/Db/VectorSourceDeletion.php'),'Wrong candidate code loaded');
$outbox=new VectorOutboxRepository($db);$owners=new VectorOwnerFenceRepository($db,$outbox);
$generations=new VectorGenerationRepository($db,$outbox,$owners);$prepared=new GenerationChunkRepository($db,$outbox,$owners);
$deletion=new VectorSourceDeletion($db,$outbox);$docs=new DocumentMapper($db,$deletion);$chunks=new ChunkMapper($db,$deletion);
$uid=$testUser;
$assert(OC::$server->get(OCP\IUserManager::class)->userExists($uid), 'Create the dedicated audit account first.');
$events=static function(string $generationId)use($db,$uid):array{$q=$db->getQueryBuilder();$q->select('id','operation')->from('eva_ai_vec_outbox')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('generation_id',$q->createNamedParameter($generationId)))->orderBy('id','ASC');$r=$q->executeQuery();try{return $r->fetchAll();}finally{$r->closeCursor();}};
$legacyCount=static function(int $documentId)use($db):int {
    $q=$db->getQueryBuilder();
    $q->selectAlias($q->createFunction('COUNT(*)'),'n')->from('eva_ai_chunks')
        ->where($q->expr()->eq('document_id',$q->createNamedParameter($documentId,OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
        ->andWhere($q->expr()->isNull('vector_generation_id'));
    $r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
};
$db->beginTransaction();
try {
 $g=new VectorGeneration(bin2hex(random_bytes(16)),$uid,'qdrant','ollama',null,'runtime-sql-fixture',hash('sha256','fixture-embedding'),3,900,120);
 $generations->create($g);$generations->bindTarget($uid,$g->id,hash('sha256','fixture-target'));
 $d=new Document();$d->setUserId($uid);$d->setFileId(2000000000);$d->setPath('__eva_runtime_audit__/source.txt');$d->setName('source.txt');$d->setSource('files');$d->setContentHash(md5('Runtime fixture'));$d->setFileMtime(1);$d->setSize(15);$d->setMime('text/plain');$d=$docs->insert($d);
 $c=new Chunk();$c->setDocumentId($d->getId());$c->setChunkIndex(0);$c->setContent('Runtime fixture');$c->setEmbeddingArray([1,0,0]);$c->setTokenCount(4);$c->setProvenanceArray([]);$chunks->insert($c);
 $s=$prepared->snapshot($uid,$d->getId());$p=new PreparedVectorChunk(0,'Runtime fixture',[1,0,0],4);
 $first=$prepared->putBuildingChunk($g,hash('sha256','fixture-target'),$s,$p);
 $same=$prepared->putBuildingChunk($g,hash('sha256','fixture-target'),$s,$p);
 $assert($same['changed']===false&&$first['id']===$same['id'],'Repeat stage was not idempotent');
 $d->setPath('__eva_runtime_audit__/renamed.txt');$docs->update($d);
 $assert($prepared->pointForDelivery($g,$first['id'],$first['revision'])===null,'Rename retained a stale prepared point');
 $assert($legacyCount($d->getId())===1,'Rename removed legacy retrieval data');
 $second=$prepared->putBuildingChunk($g,hash('sha256','fixture-target'),$prepared->snapshot($uid,$d->getId()),$p);
 $assert($second['id']>$first['id'],'Rebuild reused a terminal point ID');
 $assert($prepared->pointForDelivery($g,$second['id'],$second['revision'])!==null,'Restaged point is not deliverable');
 $docs->delete($d);
 $assert($docs->findById($d->getId())===null,'Document removal failed');
 $assert($prepared->pointForDelivery($g,$second['id'],$second['revision'])===null,'Delete retained prepared rows');
 $rows=$events($g->id);$ops=array_column($rows,'operation');
 $assert(count(array_filter($ops,static fn($op)=>$op==='delete_chunk'))===2,'Missing durable tombstones');
 $assert($legacyCount($d->getId())===0,'Delete retained legacy fixture');
 echo "PASS: actual SQL staging, idempotence, rename invalidation, legacy preservation, fresh point IDs and atomic document/tombstone removal.\n";
} finally { $db->rollBack(); }
echo "Fixture transaction rolled back; no remote request or retained generation data.\n";
