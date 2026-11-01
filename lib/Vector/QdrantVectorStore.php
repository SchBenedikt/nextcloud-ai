<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Optional vector index; native text retrieval requires an explicit generation opt-in. */
class QdrantVectorStore implements VectorStore {
    private ?\Closure $beforeTransfer = null;
    // Qdrant numeric range comparisons use doubles; retain exact revision ordering.
    private const MAX_REVISION = 9007199254740991;
    private const INDEXES = ['user_id' => 'keyword', 'generation_id' => 'keyword', 'document_id' => 'integer', 'source' => 'keyword', 'scope_paths' => 'keyword', 'revision' => 'integer', 'deleted' => 'bool'];
    public function __construct(private QdrantConnection $connection, private ?string $targetHash = null) {}

    public function forTarget(string $targetHash, ?\Closure $beforeTransfer = null): self {
        if (preg_match('/^[a-f0-9]{64}$/D', $targetHash) !== 1) throw new VectorStoreException('invalid_target');
        $store = new self($this->connection, $targetHash);
        $store->beforeTransfer = $beforeTransfer;
        return $store;
    }

    public function health(): array {
        try {
            $data = $this->request('get', '/', null, false, 3);
            $version = $data['version'] ?? '';
            if (!is_string($version) || !preg_match('/^1\.\d+\.\d+(?:[-+].*)?$/D', $version) || version_compare($version, '1.16.0', '<')) {
                return ['available' => false, 'code' => 'unsupported_version'];
            }
            return ['available' => true, 'code' => 'api_reachable', 'version' => $version];
        } catch (VectorStoreException $e) { return ['available' => false, 'code' => $e->reason]; }
        catch (\Throwable) { return ['available' => false, 'code' => 'unavailable']; }
    }

    /** Explicit preparation, not a constructor or installation side effect. */
    public function prepare(VectorGeneration $generation): void {
        $this->assertQdrant($generation);
        $health = $this->health();
        if (!$health['available']) throw new VectorStoreException($health['code']);
        if ($generation->includeTextPayload && version_compare((string)($health['version'] ?? '0'), '1.20.0', '<')) {
            throw new VectorStoreException('native_bm25_requires_qdrant_1_20');
        }
        $indexes = self::INDEXES;
        if ($generation->includeTextPayload) {
            $indexes['content'] = ['type' => 'text', 'tokenizer' => 'word', 'lowercase' => true,
                'phrase_matching' => true, 'scoring' => ['type' => 'bm25']];
        }
        $path = $this->collection($generation);
        $data = $this->request('get', $path, null, true);
        if ($data === null) {
            try { $this->ok($this->request('put', $path, ['vectors' => ['dense' => ['size' => $generation->dimension, 'distance' => 'Cosine']]])); }
            catch (VectorStoreException $e) {
                // Another explicit preparer may have won collection creation.
                if ($e->reason !== 'http_409') throw $e;
            }
            $data = $this->request('get', $path);
        }
        $this->ok($data);
        $dense = $data['result']['config']['params']['vectors']['dense'] ?? null;
        if (!is_array($dense) || ($dense['size'] ?? null) !== $generation->dimension || ($dense['distance'] ?? '') !== 'Cosine') {
            throw new VectorStoreException('collection_identity_mismatch');
        }
        $schema = $data['result']['payload_schema'] ?? [];
        if (!is_array($schema)) throw new VectorStoreException('invalid_payload_schema');
        foreach ($indexes as $field => $fieldSchema) {
            $type = is_array($fieldSchema) ? 'text' : $fieldSchema;
            if (isset($schema[$field])) {
                if (($schema[$field]['data_type'] ?? '') !== $type) throw new VectorStoreException('payload_index_mismatch');
                if (is_array($fieldSchema) && (($schema[$field]['params']['scoring']['type'] ?? '') !== 'bm25'
                    || ($schema[$field]['params']['phrase_matching'] ?? false) !== true)) throw new VectorStoreException('payload_index_mismatch');
                continue;
            }
            $this->completed($this->request('put', $path . '/index?wait=true&ordering=strong', ['field_name' => $field, 'field_schema' => $fieldSchema]));
        }
    }

    public function upsert(VectorGeneration $generation, array $points, int $revision): void {
        $this->assertQdrant($generation);
        $this->validateRevision($revision);
        if (!array_is_list($points) || count($points) > 200) throw new VectorStoreException('invalid_batch');
        if ($points === []) return;
        $prepared = []; $ids = []; $elements = 0;
        foreach ($points as $point) {
            if (!is_array($point) || !is_int($point['id'] ?? null) || $point['id'] < 1 || isset($ids[$point['id']])
                || !is_int($point['document_id'] ?? null) || $point['document_id'] < 1
                || !is_int($point['file_id'] ?? null) || !is_int($point['chunk_index'] ?? null) || $point['chunk_index'] < 0
                || !in_array($point['source'] ?? null, ['files', 'mail', 'talk'], true)
                || !is_string($point['path'] ?? null) || $point['path'] === ''
                || !is_string($point['content_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $point['content_hash']) !== 1
                || !is_array($point['vector'] ?? null)) throw new VectorStoreException('invalid_point');
            if ($generation->includeTextPayload && (!is_string($point['content'] ?? null) || $point['content'] === ''
                || strlen($point['content']) > 40000 || !hash_equals($point['content_hash'], hash('sha256', $point['content'])))) {
                throw new VectorStoreException('invalid_point');
            }
            if ($point['source'] === 'files') {
                new SearchFilters($point['path']);
                $scopes = $this->scopes($point['path']);
            } else {
                if (!str_starts_with($point['path'], $point['source'] . '://') || strlen($point['path']) > 4096
                    || preg_match('/[\x00-\x1f\x7f]/', $point['path'])) throw new VectorStoreException('invalid_point');
                $scopes = []; // Mail/Talk URIs are not Files folder ancestors.
            }
            $generation->validateVector($point['vector']);
            $elements += count($point['vector']);
            if ($elements > 250000) throw new VectorStoreException('batch_too_large');
            $ids[$point['id']] = true;
            $payload = ['user_id' => $this->tenant($generation), 'generation_id' => $generation->id, 'revision' => $revision, 'deleted' => false,
                'document_id' => $point['document_id'], 'file_id' => $point['file_id'], 'chunk_index' => $point['chunk_index'],
                'source' => $point['source'], 'content_hash' => $point['content_hash'], 'scope_paths' => $scopes];
            if ($generation->includeTextPayload) $payload['content'] = $point['content'];
            $prepared[] = ['id' => $point['id'], 'vector' => ['dense' => array_map('floatval', $point['vector'])], 'payload' => $payload];
        }
        // Validate the entire batch before the first network request.
        $this->writeRevision($generation, $prepared, $revision, false);
    }

    public function tombstoneChunks(VectorGeneration $generation, array $chunkIds, int $revision): void {
        $this->assertQdrant($generation);
        $this->validateRevision($revision);
        if (!array_is_list($chunkIds) || count($chunkIds) > 200) throw new VectorStoreException('invalid_batch');
        if ($chunkIds === []) return;
        if (count($chunkIds) * $generation->dimension > 250000) throw new VectorStoreException('batch_too_large');
        // Overwrite the old embedding and metadata; a tombstone must reveal no file content.
        $dummy = array_fill(0, $generation->dimension, 0.0); $dummy[0] = 1.0;
        $points = []; $seen = [];
        foreach ($chunkIds as $id) {
            if (!is_int($id) || $id < 1 || isset($seen[$id])) throw new VectorStoreException('invalid_point');
            $seen[$id] = true;
            $points[] = ['id' => $id, 'vector' => ['dense' => $dummy], 'payload' => [
                'user_id' => $this->tenant($generation), 'generation_id' => $generation->id,
                'revision' => $revision, 'deleted' => true]];
        }
        // Materialize even missing points: physical deletion would allow a late insert.
        $this->writeRevision($generation, $points, $revision, true);
    }

    /** Exact count is used only by the offline generation verification workflow. */
    public function countLive(VectorGeneration $generation): int {
        $this->assertQdrant($generation);
        $data=$this->request('post',$this->collection($generation).'/points/count?timeout=10',[
            'filter'=>['must'=>[
                ['key'=>'user_id','match'=>['value'=>$this->tenant($generation)]],
                ['key'=>'generation_id','match'=>['value'=>$generation->id]],
                ['key'=>'deleted','match'=>['value'=>false]],
            ]],
            'exact'=>true,
        ],false,12);
        $this->ok($data);
        $count=$data['result']['count']??null;
        if(!is_int($count)||$count<0)throw new VectorStoreException('invalid_count_response');
        return $count;
    }

    /** Exact count of live opted-in points whose content payload is missing or empty. */
    public function countMissingTextPayload(VectorGeneration $generation): int {
        $this->assertQdrant($generation);
        if (!$generation->includeTextPayload) throw new VectorStoreException('native_hybrid_unavailable');
        $data=$this->request('post',$this->collection($generation).'/points/count?timeout=10',[
            'filter'=>['must'=>[
                ['key'=>'user_id','match'=>['value'=>$this->tenant($generation)]],
                ['key'=>'generation_id','match'=>['value'=>$generation->id]],
                ['key'=>'deleted','match'=>['value'=>false]],
                ['is_empty'=>['key'=>'content']],
            ]],
            'exact'=>true,
        ],false,12);
        $this->ok($data);
        $count=$data['result']['count']??null;
        if(!is_int($count)||$count<0)throw new VectorStoreException('invalid_count_response');
        return $count;
    }

    /** Retrieve at most 100 exact IDs; callers compare SQL identity and vectors. */
    public function retrieve(VectorGeneration $generation,array $ids,bool $withVector=true,bool $withText=true): array {
        $this->assertQdrant($generation);
        if(!array_is_list($ids)||count($ids)>100)throw new VectorStoreException('invalid_batch');
        if($ids===[])return [];
        $seen=[];
        foreach($ids as$id){if(!is_int($id)||$id<1||isset($seen[$id]))throw new VectorStoreException('invalid_point');$seen[$id]=true;}
        $payloadFields=['user_id','generation_id','revision','deleted','document_id','source','content_hash'];
        if($generation->includeTextPayload&&$withText)$payloadFields[]='content';
        $data=$this->request('post',$this->collection($generation).'/points?timeout=10',[
            'ids'=>$ids,'with_payload'=>$payloadFields,
            'with_vector'=>$withVector,
        ],false,12);
        $this->ok($data);
        $points=$data['result']??null;
        if(!is_array($points)||!array_is_list($points)||count($points)>count($ids))throw new VectorStoreException('invalid_retrieve_response');
        $result=[];
        foreach($points as$point){
            $id=$point['id']??null;$payload=$point['payload']??null;$rawVector=$point['vector']['dense']??null;
            if(!is_int($id)||!isset($seen[$id])||isset($result[$id])||!is_array($payload)
                ||($payload['user_id']??null)!==$this->tenant($generation)||($payload['generation_id']??null)!==$generation->id
                ||!is_int($payload['revision']??null)||$payload['revision']<1||$payload['revision']>self::MAX_REVISION
                ||!is_bool($payload['deleted']??null))throw new VectorStoreException('point_identity_mismatch');
            if($generation->includeTextPayload&&$withText&&(!is_string($payload['content']??null)||strlen($payload['content'])>40000
                ||!is_string($payload['content_hash']??null)||!hash_equals($payload['content_hash'],hash('sha256',$payload['content']))))
                throw new VectorStoreException('point_text_payload_mismatch');
            $item=['id'=>$id,'payload'=>$payload];
            if($withVector){
                if(!is_array($rawVector)||!array_is_list($rawVector)||count($rawVector)!==$generation->dimension)throw new VectorStoreException('point_dimension_mismatch');
                $vector=[];foreach($rawVector as$value){if(!is_int($value)&&!is_float($value))throw new VectorStoreException('invalid_point_vector');$vector[]=(float)$value;}
                try{$generation->validateVector($vector);}catch(\Throwable){throw new VectorStoreException('invalid_point_vector');}
                $item['vector']=$vector;
            }
            $result[$id]=$item;
        }
        return $result;
    }

    private function writeRevision(VectorGeneration $generation, array $points, int $revision, bool $deleted): void {
        $must = [['key' => 'user_id', 'match' => ['value' => $this->tenant($generation)]],
            ['key' => 'generation_id', 'match' => ['value' => $generation->id]],
            ['key' => 'revision', 'range' => ['lte' => $revision]]];
        // Tombstones are terminal for a stable ID, even for a higher-revision upsert.
        if (!$deleted) $must[] = ['key' => 'deleted', 'match' => ['value' => false]];
        $this->completed($this->request('put', $this->collection($generation) . '/points?wait=true&ordering=strong',
            ['points' => $points, 'update_filter' => ['must' => $must]]));
        // "completed" confirms execution, not that every conditional update matched.
        // A later generation verifier must compare IDs, revisions and deletion state.
    }

    private function validateRevision(int $revision): void {
        if ($revision < 1 || $revision > self::MAX_REVISION) throw new VectorStoreException('invalid_revision');
    }

    /** A missing collection already satisfies terminal generation erasure. */
    public function deleteGeneration(VectorGeneration $generation): void {
        $this->assertQdrant($generation);
        $data = $this->request('delete', $this->collection($generation) . '?timeout=10', null, true, 10);
        if ($data !== null) {
            $this->ok($data);
            if (($data['result'] ?? null) !== true) throw new VectorStoreException('operation_not_completed');
        }
    }

    public function search(VectorGeneration $generation, array $queryVector, int $topK, SearchFilters $filters,int $timeoutSeconds=5): array {
        $this->assertQdrant($generation);
        $generation->validateVector($queryVector);
        if ($topK < 1 || $topK > 100||$timeoutSeconds<1||$timeoutSeconds>5) throw new VectorStoreException('invalid_limit');
        $data = $this->request('post', $this->collection($generation) . '/points/query?timeout=' . $timeoutSeconds,
            ['query' => array_map('floatval', $queryVector), 'using' => 'dense', 'limit' => $topK,
                'filter' => $this->filter($generation, $filters), 'with_vector' => false,
                'with_payload' => ['user_id', 'generation_id', 'document_id', 'source', 'scope_paths', 'revision', 'deleted']], false, $timeoutSeconds);
        $this->ok($data);
        $points = $data['result']['points'] ?? null;
        if (!is_array($points) || !array_is_list($points) || count($points) > $topK) throw new VectorStoreException('invalid_search_response');
        $results = []; $seen = [];
        foreach ($points as $point) {
            $id = $point['id'] ?? null;
            $payload = $point['payload'] ?? null;
            $score = $point['score'] ?? null;
            if (!is_int($id) || $id < 1 || isset($seen[$id]) || (!is_int($score) && !is_float($score)) || !is_finite((float)$score)
                || !is_array($payload) || ($payload['user_id'] ?? '') !== $this->tenant($generation) || ($payload['generation_id'] ?? '') !== $generation->id
                || ($payload['deleted'] ?? null) !== false
                || !is_int($payload['revision'] ?? null) || $payload['revision'] < 1 || $payload['revision'] > self::MAX_REVISION
                || !is_int($payload['document_id'] ?? null) || $payload['document_id'] < 1
                || !in_array($payload['source'] ?? null, ['files', 'mail', 'talk'], true)
                || ($filters->source !== null && $payload['source'] !== $filters->source)
                || ($filters->documentIds !== [] && !in_array($payload['document_id'], $filters->documentIds, true))
                || ($filters->scopePath !== null && $filters->scopePath !== '' && (!is_array($payload['scope_paths'] ?? null) || !in_array(hash('sha256', $filters->scopePath), $payload['scope_paths'], true)))) {
                // Do not quietly drop a cross-tenant response and conceal a violated invariant.
                throw new VectorStoreException('search_isolation_violation');
            }
            $seen[$id] = true;
            $results[] = ['chunkId' => $id, 'revision' => $payload['revision'], 'score' => (float)$score];
        }
        return $results;
    }

    /** Native Qdrant BM25 plus dense RRF for a generation with explicit text-transfer consent. */
    public function searchHybrid(VectorGeneration $generation, string $query, ?array $queryVector, int $topK, SearchFilters $filters, int $timeoutSeconds = 5): array {
        $this->assertQdrant($generation);
        if (!$generation->includeTextPayload || trim($query) === '' || strlen($query) > 8000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $query)
            || $topK < 1 || $topK > 100 || $timeoutSeconds < 1 || $timeoutSeconds > 5) {
            throw new VectorStoreException('native_hybrid_unavailable');
        }
        if ($queryVector !== null) $generation->validateVector($queryVector);
        $filter = $this->filter($generation, $filters);
        $textPrefetch = ['query' => ['text' => $query], 'using' => 'content', 'limit' => $topK, 'filter' => $filter];
        if ($queryVector === null) {
            $request = ['query' => ['text' => $query], 'using' => 'content', 'limit' => $topK, 'filter' => $filter];
        } else {
            $densePrefetch = ['query' => array_map('floatval', $queryVector), 'using' => 'dense', 'limit' => $topK, 'filter' => $filter];
            $request = ['prefetch' => [$textPrefetch, $densePrefetch], 'query' => ['fusion' => 'rrf'],
                'limit' => $topK, 'filter' => $filter];
        }
        $request += ['with_vector' => false,
            'with_payload' => ['user_id', 'generation_id', 'document_id', 'source', 'scope_paths', 'revision', 'deleted']];
        $data = $this->request('post', $this->collection($generation) . '/points/query?timeout=' . $timeoutSeconds,
            $request, false, $timeoutSeconds);
        $this->ok($data);
        $points = $data['result']['points'] ?? null;
        if (!is_array($points) || !array_is_list($points) || count($points) > $topK) throw new VectorStoreException('invalid_search_response');
        $results = []; $seen = [];
        foreach ($points as $point) {
            $id = $point['id'] ?? null; $payload = $point['payload'] ?? null; $score = $point['score'] ?? null;
            if (!is_int($id) || $id < 1 || isset($seen[$id]) || (!is_int($score) && !is_float($score)) || !is_finite((float)$score)
                || !is_array($payload) || ($payload['user_id'] ?? '') !== $this->tenant($generation) || ($payload['generation_id'] ?? '') !== $generation->id
                || ($payload['deleted'] ?? null) !== false || !is_int($payload['revision'] ?? null) || $payload['revision'] < 1 || $payload['revision'] > self::MAX_REVISION
                || !is_int($payload['document_id'] ?? null) || $payload['document_id'] < 1
                || !in_array($payload['source'] ?? null, ['files', 'mail', 'talk'], true)
                || ($filters->source !== null && $payload['source'] !== $filters->source)
                || ($filters->documentIds !== [] && !in_array($payload['document_id'], $filters->documentIds, true))
                || ($filters->scopePath !== null && $filters->scopePath !== '' && (!is_array($payload['scope_paths'] ?? null)
                    || !in_array(hash('sha256', $filters->scopePath), $payload['scope_paths'], true)))) {
                throw new VectorStoreException('search_isolation_violation');
            }
            $seen[$id] = true;
            $results[] = ['chunkId' => $id, 'revision' => $payload['revision'], 'score' => (float)$score];
        }
        return $results;
    }

    private function filter(VectorGeneration $generation, SearchFilters $filters): array {
        $must = [['key' => 'user_id', 'match' => ['value' => $this->tenant($generation)]],
            ['key' => 'generation_id', 'match' => ['value' => $generation->id]],
            ['key' => 'deleted', 'match' => ['value' => false]]];
        if ($filters->source !== null) $must[] = ['key' => 'source', 'match' => ['value' => $filters->source]];
        if ($filters->documentIds !== []) $must[] = ['key' => 'document_id', 'match' => ['any' => $filters->documentIds]];
        if ($filters->scopePath !== null && $filters->scopePath !== '') $must[] = ['key' => 'scope_paths', 'match' => ['value' => hash('sha256', $filters->scopePath)]];
        return ['must' => $must];
    }

    private function scopes(string $path): array {
        $parts = explode('/', $path); $prefix = ''; $tokens = [];
        if (count($parts) > 128) throw new VectorStoreException('scope_too_deep');
        foreach ($parts as $part) { $prefix .= ($prefix === '' ? '' : '/') . $part; $tokens[] = hash('sha256', $prefix); }
        return $tokens;
    }
    private function request(string $method, string $path, ?array $payload = null, bool $allowMissing = false, int $timeout = 30): ?array {
        return $this->connection->request($method, $path, $payload, $allowMissing, $timeout, $this->targetHash, $this->beforeTransfer);
    }

    private function collection(VectorGeneration $generation): string { return '/collections/' . $generation->collection(); }
    private function assertQdrant(VectorGeneration $generation): void {
        if ($generation->backend !== 'qdrant') throw new VectorStoreException('generation_backend_mismatch');
    }
    private function tenant(VectorGeneration $generation): string { return hash('sha256', $generation->userId); }
    private function ok(?array $data): void { if (($data['status'] ?? null) !== 'ok') throw new VectorStoreException('invalid_operation_response'); }
    private function completed(?array $data): void {
        $this->ok($data);
        if (($data['result']['status'] ?? '') !== 'completed') throw new VectorStoreException('operation_not_completed');
    }
}
