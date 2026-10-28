<?php
declare(strict_types=1);

// Explicit opt-in: no real user, provider key, native preference or global flag is mutated.
if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only.');
$root=getenv('NEXTCLOUD_ROOT');$url=getenv('EVA_EMBED_TEST_URL');$counter=getenv('EVA_EMBED_FIXTURE_COUNTER');
if (!$root || !is_file($root.'/occ') || !$url || !preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$url)
    || !$counter || !preg_match('~^/tmp/eva-embedding-fixture-[a-z0-9-]+\.count$~D',$counter)) throw new RuntimeException('Set the Nextcloud root, disposable loopback fixture URL and counter path.');
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']=$root.'/occ';$_SERVER['SCRIPT_NAME']='/occ';chdir($root);require $root.'/lib/base.php';
$loader=require dirname(__DIR__,2).'/vendor/autoload.php';$loader->addPsr4('OCA\\EvaAi\\',dirname(__DIR__,2).'/lib/',true);$loader->register(true);
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});

use OCA\EvaAi\Service\{AppConfig,EmbeddingCache,ProviderEndpointPolicy};
use OCA\EvaAi\Vector\{GenerationEmbeddingProvider,GenerationEmbeddingConfiguration,VectorGeneration,VectorStoreException};
use OCP\DB\Types;

final class EmbeddingTransportDoubles extends PHPUnit\Framework\TestCase {
    public function stub(string $class):object{return $this->createMock($class);}
    public function fixture():void{}
}
$doubles=new EmbeddingTransportDoubles('fixture');
$assert=static function(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);};
$db=OC::$server->get(OC\DB\ConnectionFactory::class)->getConnection('sqlite3',['memory'=>true,'path'=>null,'tablePrefix'=>'ep_','user'=>'','password'=>'','sharding'=>[]]);
$assert(($db->getParams()['memory']??false)===true && ($db->getParams()['path']??null)===null,'Refusing non-memory fixture SQL.');
$inner=$db;$schema=new OC\DB\SchemaWrapper($db,new Doctrine\DBAL\Schema\Schema());
foreach(['appconfig'=>['appid','configkey'],'preferences'=>['userid','appid','configkey']]as$name=>$keys){
    $table=$schema->createTable($name);
    foreach([...$keys,'configvalue']as$key)$table->addColumn($key,Types::STRING,['notnull'=>true,'length'=>16384]);
    $table->setPrimaryKey($keys);
}
foreach($schema->getWrappedSchema()->toSql($db->getDatabasePlatform())as$sql)$db->executeStatement($sql);
if(!$db instanceof OCP\IDBConnection)$db=new OC\DB\ConnectionAdapter($db);
$put=static function(string $table,array $keys,string $value)use($db):void{
    $db->insertIgnoreConflict($table,$keys+['configvalue'=>$value]);$q=$db->getQueryBuilder();$q->update($table)->set('configvalue',$q->createNamedParameter($value));
    foreach($keys as$key=>$v)$q->andWhere($q->expr()->eq($key,$q->createNamedParameter($v)));$q->executeStatement();
};
$put('appconfig',['appid'=>'eva_ai','configkey'=>'ollama_url'],$url);
$profiles=json_encode([['id'=>'local','url'=>$url.'/v1','auth'=>'api_key']]);
$put('appconfig',['appid'=>'eva_ai','configkey'=>'provider_profiles'],$profiles);
$crypto=OC::$server->get(OCP\Security\ICrypto::class);
$put('preferences',['userid'=>'fixture-alice','appid'=>'eva_ai','configkey'=>'provider_local_api_key'],$crypto->encrypt('fixture-secret'));
$native=$doubles->stub(OCP\IConfig::class);
$native->method('getAppValue')->willReturnCallback(static fn($app,$key,$default='')=>$key==='provider_private_origins'?json_encode([$url]):$default);
$native->method('getSystemValue')->willReturnCallback(static fn($key,$default='')=>$default);
$policy=new class($native) extends ProviderEndpointPolicy {
    public ?Closure $hook=null;
    public function handler(?Closure $guard=null):callable{return parent::handler(function()use($guard):void{if($this->hook)($this->hook)();if($guard)$guard();});}
};
$owner='fixture-alice';$config=$doubles->stub(AppConfig::class);
$config->method('userId')->willReturnCallback(static function()use(&$owner){return $owner;});
$config->method('providerEndpointPolicy')->willReturn($policy);
$values=[];$backend=$doubles->stub(OCP\ICache::class);
$backend->method('get')->willReturnCallback(static function($key)use(&$values){return $values[$key]??null;});
$backend->method('set')->willReturnCallback(static function($key,$value,$ttl=0)use(&$values){$values[$key]=$value;return true;});
$factory=$doubles->stub(OCP\ICacheFactory::class);$factory->method('createDistributed')->willReturn($backend);
$cache=new EmbeddingCache($factory,$config,OC::$server->get(Psr\Log\LoggerInterface::class));
$configuration=new GenerationEmbeddingConfiguration($db,$crypto);
$provider=new GenerationEmbeddingProvider($config,OC::$server->get(OCP\Http\Client\IClientService::class),$configuration,$cache);
$generation=static fn(string $owner,string $providerType='ollama')=>new VectorGeneration(bin2hex(random_bytes(16)),$owner,'qdrant',$providerType,$providerType==='ollama'?null:'local','fixture-embed',hash('sha256',$url.($providerType==='ollama'?'':'/v1')),3,900,120);
$guard=static function()use($db,$assert):void{$assert(!$db->inTransaction(),'HTTP/cache guard ran inside a SQL transaction.');};
$requests=static fn():int=>is_file($counter)?(int)file_get_contents($counter):0;
$before=$requests();
try {
    $g=$generation($owner);$vectors=$provider->embed($g,['one','longer','one'],$guard);
    $assert($vectors===[[3.0,1.0,2.0],[6.0,1.0,2.0],[3.0,1.0,2.0]],'Real Ollama-contract ordering or duplicate restoration failed.');
    $assert($requests()===$before+1,'Duplicate texts were not coalesced.');
    $provider->embed($generation($owner),['one','longer'],$guard);
    $assert($requests()===$before+1,'Compatible generation cache reuse failed.');
    $provider->embed($g,['one'],$guard,false);
    $assert($requests()===$before+2,'Explicit probe incorrectly used cache.');
    $open=$generation($owner,'openai_compatible');
    $assert($provider->embed($open,['x','lengthy'],$guard)===[[1.0,1.0,2.0],[7.0,1.0,2.0]],'Real compatible API/auth/index ordering failed.');
    $assert($requests()===$before+3,'Compatible transfer did not occur.');
    $owner='fixture-bob';
    try{$provider->embed($generation($owner,'openai_compatible'),['x'],$guard);throw new RuntimeException('Another owner inherited a provider key.');}
    catch(VectorStoreException $e){$assert($e->reason==='embedding_key_unavailable','Wrong missing-owned-key result.');}
    $provider->embed($generation($owner),['one'],$guard);
    $assert($requests()===$before+4,'Another owner reused the first owner cache.');
    $owner='fixture-alice';
    foreach(['profile','key']as$kind){
        $policy->hook=static function()use($kind,$put,$url,$crypto):void{
            if($kind==='profile')$put('appconfig',['appid'=>'eva_ai','configkey'=>'provider_profiles'],json_encode([['id'=>'local','url'=>$url.'/changed','auth'=>'api_key']]));
            else $put('preferences',['userid'=>'fixture-alice','appid'=>'eva_ai','configkey'=>'provider_local_api_key'],$crypto->encrypt('rotated-fixture-secret'));
        };
        try{$provider->embed($open,['guard-'.$kind],$guard,false);throw new RuntimeException('Changed SQL configuration reached transport.');}
        catch(VectorStoreException $e){$assert($e->reason==='embedding_profile_changed','Wrong transfer-time configuration rejection.');}
        $assert($requests()===$before+4,'A post-DNS changed profile/key was transferred.');
        $policy->hook=null;$put('appconfig',['appid'=>'eva_ai','configkey'=>'provider_profiles'],$profiles);
        $put('preferences',['userid'=>'fixture-alice','appid'=>'eva_ai','configkey'=>'provider_local_api_key'],$crypto->encrypt('fixture-secret'));
    }
    $q=$db->getQueryBuilder();$q->delete('appconfig')->where($q->expr()->eq('appid',$q->createNamedParameter('eva_ai')))->andWhere($q->expr()->eq('configkey',$q->createNamedParameter('ollama_url')))->executeStatement();
    $assert($configuration->capture($g)['url']===AppConfig::DEFAULT_OLLAMA_URL,'Missing instance setting did not preserve the native default.');
    echo "PASS: native HTTP Ollama/compatible contracts, owned encrypted-key isolation, fresh SQL post-DNS profile/key fencing, ordered/coalesced inputs and cross-generation/user-isolated cache.\n";
    echo "Isolated in-memory configuration only; no native user, global flag or real provider credential was modified.\n";
} finally {$inner->close();}
