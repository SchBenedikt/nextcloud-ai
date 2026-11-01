<?php
declare(strict_types=1);
// Native database repository probe: all mutations are rolled back, no external I/O.
$root=getenv('NEXTCLOUD_ROOT');$uid=getenv('EVA_TEST_USER');
if(PHP_SAPI!=='cli'||!$root||!is_file($root.'/occ')||!$uid||!str_starts_with($uid,'eva-runtime-audit-'))throw new RuntimeException('Set NEXTCLOUD_ROOT and an existing dedicated EVA_TEST_USER.');
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']=$root.'/occ';$_SERVER['SCRIPT_NAME']='/occ';chdir($root);require $root.'/lib/base.php';
$loader=require dirname(__DIR__,2).'/vendor/autoload.php';$loader->addPsr4('OCA\\EvaAi\\',dirname(__DIR__,2).'/lib/',true);$loader->register(true);
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
use OCA\EvaAi\Db\{VectorOutboxRepository,VectorOwnerFenceRepository,VectorGenerationRepository,GenerationChunkRepository,GenerationBuildRepository,DocumentMapper};
use OCA\EvaAi\Vector\{VectorGeneration,VectorStoreException};
use OCA\EvaAi\Dto\PreparedVectorChunk;
final class BuildSqlDoubles extends PHPUnit\Framework\TestCase {
    public function journal(OCP\IDBConnection $db):VectorOutboxRepository {
        $value=$this->getMockBuilder(VectorOutboxRepository::class)->setConstructorArgs([$db])->onlyMethods(['pointsEnabled','pointTargetIsCurrent'])->getMock();
        $value->method('pointsEnabled')->willReturn(true);$value->method('pointTargetIsCurrent')->willReturn(true);return $value;
    }
    public function fixture():void{}
}
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$assert(OC::$server->get(OCP\IUserManager::class)->userExists($uid),'Create the dedicated audit account first.');
$db=OC::$server->get(OCP\IDBConnection::class);$assert(!$db->inTransaction(),'Unexpected outer transaction.');
// Never claim an unrelated real build with fixture admission permissions.
$q=$db->getQueryBuilder();$q->selectAlias($q->createFunction('COUNT(*)'),'n')->from('eva_ai_vec_builds');$r=$q->executeQuery();$count=(int)$r->fetchOne();$r->closeCursor();$assert($count===0,'Run this local probe only with an empty build queue.');
$document=OC::$server->get(DocumentMapper::class)->findByUser($uid,null,1,0)[0]??null;$assert($document!==null,'Create a public canonical audit document first.');
$journal=(new BuildSqlDoubles('fixture'))->journal($db);$owners=new VectorOwnerFenceRepository($db,$journal);
$generations=new VectorGenerationRepository($db,$journal,$owners);$chunks=new GenerationChunkRepository($db,$journal,$owners);$builds=new GenerationBuildRepository($db,$journal,$owners,$generations);
$generation=new VectorGeneration(bin2hex(random_bytes(16)),$uid,'qdrant','ollama',null,'public-sql-fixture',hash('sha256','fixture'),3,128,12);$target=hash('sha256','fixture-only-no-network');
$db->beginTransaction();
try {
    $generations->create($generation);$generations->bindTarget($uid,$generation->id,$target);
    $builds->start($uid,$generation->id,$target);$claim=$builds->claim(time());$assert($claim!==null&&$claim['generation_id']===$generation->id,'Native database claim failed');
    $assert($builds->claim(time())===null,'Native database double-claimed a live lease');
    $snapshot=$chunks->snapshot($uid,(int)$document->getId());$assert($snapshot!==null,'Native canonical snapshot missing');
    try {
        $builds->withLease($claim,function()use($builds,$claim,$chunks,$generation,$target,$snapshot):void{
            $chunks->putBuildingChunk($generation,$target,$snapshot,new PreparedVectorChunk(0,'Public SQL fixture',[1,0,0],5));
            $builds->checkpoint($claim,['document_id'=>$snapshot->id,'chunk_cursor'=>1,'plan_hash'=>hash('sha256','fixture')]);
            throw new RuntimeException('Injected checkpoint rollback');
        });
        throw new RuntimeException('Checkpoint failure was hidden');
    } catch(RuntimeException $e){$assert($e->getMessage()==='Injected checkpoint rollback','Unexpected rollback result');}
    $assert($builds->stagedCount($generation->id)===0&&$builds->find($uid,$generation->id)['chunk_cursor']===0,'Native chunk/checkpoint rollback failed');
    $q=$db->getQueryBuilder();$q->selectAlias($q->createFunction('COUNT(*)'),'n')->from('eva_ai_vec_outbox')->where($q->expr()->eq('generation_id',$q->createNamedParameter($generation->id)));$r=$q->executeQuery();$assert((int)$r->fetchOne()===0,'Native outbox rollback failed');$r->closeCursor();
    $builds->withLease($claim,function()use($builds,$claim,$chunks,$generation,$target,$snapshot):void{
        $chunks->putBuildingChunk($generation,$target,$snapshot,new PreparedVectorChunk(0,'Public SQL fixture',[1,0,0],5));
        $builds->checkpoint($claim,['document_id'=>$snapshot->id,'chunk_cursor'=>1,'plan_hash'=>hash('sha256','fixture')]);
    });
    $assert($builds->stagedCount($generation->id)===1&&$builds->find($uid,$generation->id)['chunk_cursor']===1,'Native checkpoint commit failed');
    $builds->control($uid,$generation->id,'pause');
    try{$builds->withLease($claim,static fn()=>null);throw new RuntimeException('Paused native lease wrote again');}catch(VectorStoreException $e){$assert($e->reason==='stale_build_lease','Wrong paused lease result');}
    $builds->control($uid,$generation->id,'resume');$next=$builds->claim(time());$assert($next!==null&&$next['lease_token']!==$claim['lease_token'],'Native resume did not replace lease token');
    $builds->release($claim,'old_process');$assert($builds->find($uid,$generation->id)['lease_token']===$next['lease_token'],'Native stale release overwrote its successor');
    echo "PASS: actual native database build claim, exclusive lease, chunk/outbox/checkpoint rollback and commit, pause/resume and stale-token protection.\n";
} finally {$db->rollBack();}
$assert($builds->find($uid,$generation->id)===null&&$generations->find($uid,$generation->id)===null,'Outer native fixture rollback retained state');
echo "Outer fixture transaction rolled back. Admission switches were test doubles; live flags, providers and existing sources were not changed.\n";
