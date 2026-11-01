<?php
declare(strict_types=1);

namespace OCA\EvaAi\Vector;

use OCA\EvaAi\Db\GenerationBuildRepository;
use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Service\{AppConfig,EmbeddingCache,EmbeddingProvider};
use Psr\Log\LoggerInterface;

/** Starts the internal SQL generation build after a user has enrolled in indexing. */
final class DefaultSqlGenerationProvisioner {
    public function __construct(
        private AppConfig $config,
        private VectorGenerationRepository $generations,
        private GenerationBuildRepository $builds,
        private EmbeddingCache $cache,
        private LoggerInterface $logger,
    ) {}

    /** @param list<array{text:string,vector:list<float|int>}> $provenVectors */
    public function ensure(string $userId, string $model, int $dimension, array $provenVectors = []): void {
        if ($userId === '' || !$this->isEnrolled($userId)) return;
        try { $identity = $this->embeddingIdentity($userId, $model, $dimension); }
        catch (\Throwable $e) {
            $this->logger->warning('eva_ai: configured generation embedding profile is unavailable', ['exception' => $e::class]);
            return;
        }
        if ($identity['model'] === '' || $identity['dimension'] < 1) return;
        $chunkSize = $this->config->getInt('chunk_size', 900);
        $chunkOverlap = $this->config->getInt('chunk_overlap', 120);
        try {
            $retrievalBackend = $this->generations->retrievalBackendForUser($userId);
            // A deliberately selected Qdrant primary is never replaced or
            // shadowed by automatic SQL provisioning.
            if ($retrievalBackend === 'qdrant_generation') return;

            // Normal indexing journals changes for the active generation. Reuse
            // it only when the complete configured provider identity matches.
            $activeId = $this->generations->activeGenerationId($userId);
            $active = $activeId === null ? null : $this->generations->find($userId, $activeId);
            if ($this->matches($active, $identity, $chunkSize, $chunkOverlap)
                && $retrievalBackend === 'sql_generation') {
                if ($identity['provider'] === 'ollama') $this->cacheProvenVectors($active, $provenVectors);
                return;
            }

            $generation = $this->generations->findBuildingSql($userId, $identity['provider'], $identity['profile_id'],
                $identity['model'], $identity['endpoint_hash'], $identity['dimension'], $chunkSize, $chunkOverlap);
            if ($generation === null) {
                $generation = new VectorGeneration(bin2hex(random_bytes(16)), $userId, 'sql', $identity['provider'], $identity['profile_id'],
                    $identity['model'], $identity['endpoint_hash'], $identity['dimension'], $chunkSize, $chunkOverlap);
                $this->generations->create($generation);
            }
            // Legacy indexing vectors are always produced by Ollama. They can
            // seed only an Ollama generation, never a same-dimension profile.
            if ($identity['provider'] === 'ollama') $this->cacheProvenVectors($generation, $provenVectors);
            $target = $this->generations->target($userId, $generation->id);
            if ($target !== null) $this->builds->start($userId, $generation->id, $target);
        } catch (\Throwable $e) {
            // SQL generation indexing is an optimization; it must never fail the legacy index pass.
            $this->logger->warning('eva_ai: could not schedule the default SQL generation build', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function matches(?VectorGeneration $generation, array $identity, int $chunkSize, int $chunkOverlap): bool {
        return $generation !== null && $generation->backend === 'sql' && $generation->embeddingProvider === $identity['provider']
            && $generation->profileId === $identity['profile_id'] && $generation->embeddingModel === $identity['model']
            && hash_equals($generation->endpointHash, $identity['endpoint_hash'])
            && $generation->dimension === $identity['dimension'] && $generation->chunkSize === $chunkSize
            && $generation->chunkOverlap === $chunkOverlap && $this->generations->status($generation->userId, $generation->id) === 'active';
    }

    /** @return array{provider:string,profile_id:?string,model:string,dimension:int,endpoint_hash:string} */
    private function embeddingIdentity(string $userId, string $ollamaModel, int $ollamaDimension): array {
        $previous = $this->config->userId();
        try {
            $this->config->setUserId($userId);
            $profileId = trim((string)$this->config->get('generation_embedding_profile_id'));
            if ($profileId === '') {
                if ($ollamaModel === '' || $ollamaDimension < 1) return ['provider'=>'ollama','profile_id'=>null,'model'=>'','dimension'=>0,'endpoint_hash'=>''];
                return ['provider'=>'ollama','profile_id'=>null,'model'=>$ollamaModel,'dimension'=>$ollamaDimension,
                    'endpoint_hash'=>hash('sha256',rtrim($this->config->ollamaUrl(),'/'))];
            }
            $profiles=json_decode((string)$this->config->get('provider_profiles'),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($profiles)||!array_is_list($profiles)) throw new \RuntimeException('Invalid embedding provider profiles.');
            foreach($profiles as$profile){
                if(!is_array($profile)||($profile['id']??null)!==$profileId)continue;
                $url=is_string($profile['url']??null)?rtrim($profile['url'],'/'):'';
                $model=is_string($profile['embedding_model']??null)?trim($profile['embedding_model']):'';
                $dimension=filter_var($profile['embedding_dimension']??null,FILTER_VALIDATE_INT);
                if($url===''||$model===''||$dimension===false||$dimension<1||$dimension>16384)throw new \RuntimeException('Invalid embedding provider identity.');
                return ['provider'=>'openai_compatible','profile_id'=>$profileId,'model'=>$model,'dimension'=>$dimension,'endpoint_hash'=>hash('sha256',$url)];
            }
            throw new \RuntimeException('Selected embedding profile does not exist.');
        } finally { $this->config->setUserId($previous); }
    }

    /** Exact text and proven vectors are cached only under the full generation identity. */
    private function cacheProvenVectors(VectorGeneration $generation, array $entries): void {
        if ($entries === []) return;
        $texts = []; $vectors = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['text'] ?? null) || !is_array($entry['vector'] ?? null)) continue;
            try { $generation->validateVector($entry['vector']); } catch (\Throwable) { continue; }
            $texts[] = $entry['text']; $vectors[] = $entry['vector'];
        }
        if ($texts !== []) $this->cache->putForGeneration($generation, $texts, $vectors);
    }

    /**
     * Bootstrap existing enrolled indexes even when their files have not changed.
     * The unique public probe avoids sending existing document content just to
     * discover the current embedding identity. A cooldown keeps unavailable
     * providers from adding a request to every cron tick.
     */
    public function bootstrap(string $userId, EmbeddingProvider $provider): void {
        if ($userId === '' || !$this->isEnrolled($userId)
            || $this->generations->retrievalBackendForUser($userId) === 'qdrant_generation') return;

        $previous = $this->config->userId();
        try {
            $this->config->setUserId($userId);
            if (trim((string)$this->config->get('generation_embedding_profile_id')) !== '') {
                // Selecting the embedding profile is explicit consent for the
                // background generation build to send chunks to that endpoint.
                $this->ensure($userId, '', 0);
                return;
            }
            $activeId=$this->generations->activeGenerationId($userId);
            $active=$activeId===null?null:$this->generations->find($userId,$activeId);
            $expectedModel=trim((string)$this->config->get('embedding_model'));
            $expectedEndpoint=hash('sha256',rtrim($this->config->ollamaUrl(),'/'));
            if($active!==null&&$active->backend==='sql'&&$active->embeddingProvider==='ollama'
                &&$active->embeddingModel===$expectedModel&&hash_equals($active->endpointHash,$expectedEndpoint)
                &&$active->chunkSize===$this->config->getInt('chunk_size',900)
                &&$active->chunkOverlap===$this->config->getInt('chunk_overlap',120))return;
            if ((int)$this->config->get('sql_generation_probe_after') > time()) return;
            $this->config->set('sql_generation_probe_after', (string)(time() + 21600));
            [$vectors, $error] = $provider->embedBatch(['EVA local SQL generation compatibility probe ' . bin2hex(random_bytes(12))]);
            $model = $provider->lastEmbeddingModel();
            $vector = $vectors[0] ?? null;
            if ($error === null && $model !== null && is_array($vector) && $vector !== []) {
                $this->ensure($userId, $model, count($vector));
            }
        } catch (\Throwable $e) {
            $this->logger->debug('eva_ai: default SQL generation bootstrap deferred', ['exception' => $e::class]);
        } finally {
            $this->config->setUserId($previous);
        }
    }

    private function isEnrolled(string $userId): bool {
        // Unlike the backward-compatible build guard, bootstrap requires explicit opt-in.
        $previous = $this->config->userId();
        try {
            $this->config->setUserId($userId);
            return $this->config->get('index_enrolled') === '1';
        } finally {
            $this->config->setUserId($previous);
        }
    }
}
