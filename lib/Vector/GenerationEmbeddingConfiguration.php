<?php
declare(strict_types=1);
namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Service\AppConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

/** Fresh SQL configuration snapshots; encrypted credentials never leave this server. */
class GenerationEmbeddingConfiguration {
    public function __construct(private IDBConnection $db, private ICrypto $crypto) {}

    public function capture(VectorGeneration $generation): array {
        $setting=$generation->embeddingProvider==='ollama'?'ollama_url':'provider_profiles';
        $keys=[$setting];
        if ($generation->profileId!==null) $keys[]='provider_'.$generation->profileId.'_api_key';
        $qb=$this->db->getQueryBuilder();
        $qb->select('configkey','configvalue')->from('preferences')
            ->where($qb->expr()->eq('userid',$qb->createNamedParameter($generation->userId)))
            ->andWhere($qb->expr()->eq('appid',$qb->createNamedParameter(AppConfig::APP)))
            ->andWhere($qb->expr()->in('configkey',$qb->createNamedParameter($keys,IQueryBuilder::PARAM_STR_ARRAY)));
        $result=$qb->executeQuery();
        try { $personal=array_column($result->fetchAll(),'configvalue','configkey'); } finally { $result->closeCursor(); }
        if (array_key_exists($setting,$personal)) $raw=$personal[$setting];
        else {
            $qb=$this->db->getQueryBuilder();
            $qb->select('configvalue')->from('appconfig')->where($qb->expr()->eq('appid',$qb->createNamedParameter(AppConfig::APP)))
                ->andWhere($qb->expr()->eq('configkey',$qb->createNamedParameter($setting)));
            $result=$qb->executeQuery();
            try { $raw=$result->fetchOne(); } finally { $result->closeCursor(); }
            if (!is_string($raw) || $raw==='') $raw=$setting==='ollama_url'?AppConfig::DEFAULT_OLLAMA_URL:'[]';
        }
        if (!is_string($raw) || strlen($raw)>262144) throw new VectorStoreException('embedding_profile_invalid');
        if ($generation->embeddingProvider==='ollama') return ['url'=>rtrim($raw,'/'),'auth'=>'none','encrypted_key'=>null];
        try { $profiles=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new VectorStoreException('embedding_profile_invalid'); }
        if (!is_array($profiles) || !array_is_list($profiles) || count($profiles)>20) throw new VectorStoreException('embedding_profile_invalid');
        $matches=array_values(array_filter($profiles,static fn($profile): bool => is_array($profile) && ($profile['id']??null)===$generation->profileId));
        if (count($matches)!==1) throw new VectorStoreException('embedding_profile_missing');
        $profile=$matches[0];
        if (!is_string($profile['url']??null)) throw new VectorStoreException('embedding_profile_invalid');
        $auth=$profile['auth']??'api_key';
        $key=$personal['provider_'.$generation->profileId.'_api_key']??null;
        if ($auth==='api_key' && (!is_string($key) || $key==='' || strlen($key)>16384)) throw new VectorStoreException('embedding_key_unavailable');
        return ['url'=>rtrim((string)($profile['url']??''),'/'),'auth'=>$auth,'encrypted_key'=>$auth==='api_key'?$key:null];
    }

    public function authorization(array $snapshot): array {
        if ($snapshot['auth']==='none') return [];
        try { $key=$this->crypto->decrypt($snapshot['encrypted_key']); }
        catch (\Throwable) { throw new VectorStoreException('embedding_key_unavailable'); }
        if ($key==='' || strlen($key)>4096 || preg_match('/[\x00-\x20\x7f]/',$key)) throw new VectorStoreException('invalid_embedding_key');
        return ['Authorization'=>'Bearer '.$key];
    }
}
