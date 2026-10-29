<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\Chunk;
use OCA\EvaAi\Db\VectorGenerationRepository;
use OCA\EvaAi\Db\GenerationChunkRepository;
use OCA\EvaAi\Vector\{GenerationEmbeddingProvider,QdrantVectorStore,SearchFilters,VectorGeneration,VectorStoreException};
use OCA\EvaAi\Vector\VectorEmbeddingCodec;
use Psr\Log\LoggerInterface;

class Searcher {
    private const POOL = 8000;

    /** Rows scanned per SQL page while computing full-coverage dense scores. */
    private const DENSE_SCAN_PAGE = 2000;

    public function __construct(
        private EmbeddingProvider $ollama,
        private ChunkMapper $chunkMapper,
        private DocumentMapper $documentMapper,
        private AppConfig $config,
        private ?LoggerInterface $logger = null,
        private ?VectorGenerationRepository $generations = null,
        private ?GenerationEmbeddingProvider $generationEmbeddings = null,
        private ?QdrantVectorStore $qdrant = null,
        private ?GenerationChunkRepository $generationChunks = null,
        private ?TraceContext $traceContext = null
    ) {
    }

    /**
     * When $scopePath is set, retrieval is restricted to documents at or
     * under that path (per-chat folder scope, Issue #88). The filter runs on
     * the candidate rows before scoring, so out-of-scope chunks neither
     * rank nor consume the topK budget.
     *
     * @return array<int,array{chunk:array,doc:?array,cosine:float,lexical:float,score:float}>
     */
    public function search(string $userId, string $query, int $topK, ?string $scopePath = null, ?string $traceId = null): array {
        return $this->runSearch($userId, $query, $topK, $scopePath, null, $traceId);
    }

    /** Explicit benchmark mode: rank with the checked frozen vector, without another provider call. */
    public function searchWithQueryVector(string $userId, string $query, int $topK, array $vector, ?string $scopePath = null): array {
        if (!array_is_list($vector) || $vector === [] || count($vector) > 16384) throw new \InvalidArgumentException('Invalid frozen query vector.');
        $norm = 0.0;
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) throw new \InvalidArgumentException('Invalid frozen query vector.');
            $norm += $value * $value;
        }
        if (!is_finite($norm) || $norm <= 0) throw new \InvalidArgumentException('Invalid frozen query vector.');
        return $this->runSearch($userId, $query, $topK, $scopePath, array_map('floatval', $vector), null);
    }

    private function runSearch(string $userId, string $query, int $topK, ?string $scopePath, ?array $fixedVector, ?string $traceId): array {
        $started = hrtime(true);
        $measurements = ['backend' => 'sql_legacy', 'query_vector_mode' => $fixedVector === null ? 'automatic' : 'frozen'];
        $failed = true;
        try {
            $results = $this->searchMeasured($userId, $query, $topK, $scopePath, $measurements, $fixedVector);
            $measurements['results'] = count($results);
            $failed = false;
            return $results;
        } finally {
            $active = $measurements['_active_stage'] ?? null;
            unset($measurements['_active_stage']);
            if ($active !== null && !array_key_exists($active['name'], $measurements)) {
                $measurements[$active['name']] = (hrtime(true) - $active['started']) / 1_000_000;
            }
            $measurements['total_ms'] = (hrtime(true) - $started) / 1_000_000;
            $measurements['failed'] = $failed;
            $traceId ??= $this->traceContext?->id();
            if ($traceId !== null && preg_match('/^[a-f0-9]{32}$/D', $traceId) === 1) {
                $measurements['trace_id'] = $traceId;
                $spanId = $this->traceContext?->spanId();
                if ($spanId !== null) $measurements['span_id'] = $spanId;
            }
            if ($traceId !== null && preg_match('/^[a-f0-9]{32}$/D', $traceId) === 1) {
                $this->traceContext?->recordRetrievalMeasurements($traceId, $measurements);
            }
            try {
                // No query, account ID, path, content or exception payload.
                $this->logger?->debug('eva_ai: retrieval timings', $measurements);
            } catch (\Throwable) {
                // Telemetry must not change retrieval availability.
            }
        }
    }

    private function startStage(array &$measurements, string $name): int {
        $started = hrtime(true);
        $measurements['_active_stage'] = ['name' => $name, 'started' => $started];
        return $started;
    }

    private function searchMeasured(string $userId, string $query, int $topK, ?string $scopePath, array &$measurements, ?array $fixedVector): array {
        $topK = max(1, min($topK, (int)AppConfig::LIMITS['top_k'][1]));
        if (trim($query) === '') {
            return [];
        }
        $generation = null;
        if ($fixedVector === null && $this->generations !== null && $this->generationEmbeddings !== null) {
            try { $generation = $this->generations->activeGenerationForUser($userId); }
            catch (\Throwable) { /* Missing or unavailable generation metadata keeps legacy retrieval available. */ }
        }
        $generationId = $generation?->id;
        // Single count check: skip the embedding call entirely when the user
        // has no indexed documents. This avoids a blocking 2-30 second
        // HTTP round-trip to Ollama for a query that can never return results.
        $phase = $this->startStage($measurements, 'count_ms');
        $generationState=$generation?->backend==='qdrant'?'delivered':'ready';
        $chunkCount = $this->chunkMapper->countForUser($userId,$generationId,$generationState);
        if ($generationId !== null && $chunkCount === 0 && $this->chunkMapper->countForUser($userId) > 0) {
            // A malformed or incomplete active snapshot must not hide the still-current legacy index.
            $generation = null;
            $generationId = null;
            $chunkCount = $this->chunkMapper->countForUser($userId);
        }
        $measurements['backend'] = $generationId === null ? 'sql_legacy' : ($generation->backend==='qdrant'?'qdrant_generation':'sql_generation');
        $measurements['count_ms'] = (hrtime(true) - $phase) / 1_000_000;
        $measurements['index_chunks'] = $chunkCount;
        if ($chunkCount === 0) return [];
        if ($this->config->get('chat_provider') === 'groq' && $chunkCount === 0) return [];
        // A model named `*:cloud` is served remotely through Ollama, but the
        // configured embedding model is often still local. Embedding every
        // chat query in that situation needlessly saturates the Nextcloud
        // host's CPU while the actual answer is generated in the cloud. Keep
        // retrieval responsive by using the lexical index unless the user
        // explicitly selected a cloud embedding model as well.
        $phase = $this->startStage($measurements, 'embedding_ms');
        $chatModel = strtolower(trim($this->config->get('chat_model')));
        $embeddingModel = strtolower(trim($generation?->embeddingModel ?? $this->config->get('embedding_model')));
        $cloudChatWithLocalEmbedding = $this->config->userId() !== null
            && $this->config->get('chat_provider') === 'ollama'
            && str_ends_with($chatModel, ':cloud')
            && !str_ends_with($embeddingModel, ':cloud');
        $embeddingProvider = $fixedVector !== null ? 'frozen' : ($cloudChatWithLocalEmbedding ? 'none' : ($generation?->embeddingProvider ?? 'ollama'));
        $queryVector = $fixedVector;
        if ($queryVector === null && $generation !== null && !$cloudChatWithLocalEmbedding) {
            try {
                $guard = function () use ($userId,$generation): void {
                    if (!$this->isActiveGenerationCurrent($userId,$generation->id)) throw new VectorStoreException('generation_not_active');
                };
                $queryVector = $this->generationEmbeddings?->embed($generation,[$query],$guard,true,30)[0] ?? null;
            } catch (\Throwable) { $queryVector = null; }
        }
        if ($generation !== null && !$this->isActiveGenerationCurrent($userId,$generation->id)) {
            $generation = null;
            $generationId = null;
            $measurements['backend'] = 'sql_legacy';
            $chunkCount = $this->chunkMapper->countForUser($userId);
            $queryVector = $fixedVector;
            $embeddingModel = strtolower(trim($this->config->get('embedding_model')));
            $cloudChatWithLocalEmbedding = $this->config->userId() !== null
                && $this->config->get('chat_provider') === 'ollama' && str_ends_with($chatModel, ':cloud')
                && !str_ends_with($embeddingModel, ':cloud');
            $embeddingProvider = $fixedVector !== null ? 'frozen' : ($cloudChatWithLocalEmbedding ? 'none' : 'ollama');
        }
        if ($queryVector === null && $generation === null && !$cloudChatWithLocalEmbedding) {
            [$queryVec, $err] = $this->ollama->embedQuery([$query], $userId);
            $queryVector = $err === null && is_array($queryVec) && isset($queryVec[0]) ? $queryVec[0] : null;
        }
        $queryMagnitude = $queryVector === null ? null : $this->vectorMagnitude($queryVector);
        if ($queryMagnitude === null) $queryVector = null;
        $measurements['embedding_ms'] = (hrtime(true) - $phase) / 1_000_000;
        $measurements['embedding_provider'] = $embeddingProvider;
        $measurements['dense_enabled'] = $queryMagnitude !== null;
        $phase = $this->startStage($measurements, 'candidates_ms');
        try {
            $rows = $this->loadCandidates($userId, $query, $queryVector, $queryMagnitude, $generation, $scopePath, $topK, $measurements);
        } catch (\Throwable $error) {
            if ($generation?->backend !== 'qdrant') throw $error;
            // Remote search failure is observable and degrades to the built-in index.
            try{$this->logger?->warning('eva_ai: optional Qdrant retrieval failed; using SQL fallback', ['reason'=>$error instanceof VectorStoreException?$error->reason:'search_failed']);}catch(\Throwable){}
            $generation=null;$generationId=null;$generationState='ready';
            $measurements['backend']='sql_legacy';
            $chunkCount=$this->chunkMapper->countForUser($userId);
            $fallbackEmbeddingStarted=hrtime(true);
            $queryVector=$fixedVector;
            // A Qdrant failure can include both the generation provider's
            // initial query embedding and an Ollama fallback embedding.
            // Keep mixed time out of provider-specific latency aggregates.
            $embeddingProvider=$fixedVector!==null?'frozen':($cloudChatWithLocalEmbedding?'none':'mixed');
            if($queryVector===null&&!$cloudChatWithLocalEmbedding){
                [$fallbackVector,$fallbackError]=$this->ollama->embedQuery([$query],$userId);
                $queryVector=$fallbackError===null&&is_array($fallbackVector)&&isset($fallbackVector[0])?$fallbackVector[0]:null;
            }
            $queryMagnitude=$queryVector===null?null:$this->vectorMagnitude($queryVector);
            if($queryMagnitude===null)$queryVector=null;
            $measurements['embedding_ms']+=(hrtime(true)-$fallbackEmbeddingStarted)/1_000_000;
            $measurements['embedding_provider']=$embeddingProvider;
            $measurements['dense_enabled']=$queryMagnitude!==null;
            $rows=$this->loadCandidates($userId,$query,$queryVector,$queryMagnitude,null,$scopePath,$topK,$measurements);
        }
        $measurements['candidates_ms'] = (hrtime(true) - $phase) / 1_000_000;
        $measurements['candidates'] = count($rows);

        // Load document metadata ONCE for both scope filtering and lexical scoring.
        $phase = $this->startStage($measurements, 'hydrate_ms');
        $docIds = [];
        foreach ($rows as $row) {
            $docIds[(int)$row['document_id']] = true;
        }
        $docMeta = [];
        if ($docIds !== []) {
            $docs = $this->documentMapper->findByIds(array_keys($docIds));
            foreach ($docs as $d) {
                $docMeta[(int)$d->getId()] = $d;
            }
        }

        $measurements['hydrate_ms'] = (hrtime(true) - $phase) / 1_000_000;
        $phase = $this->startStage($measurements, 'scope_ms');
        // Apply scope filter using already-loaded metadata (no duplicate query).
        if ($scopePath !== null && trim($scopePath) !== '') {
            $rows = $this->filterByScopePath($rows, $scopePath, $docMeta);
        }

        $measurements['scope_ms'] = (hrtime(true) - $phase) / 1_000_000;
        $measurements['scoped_candidates'] = count($rows);
        $phase = $this->startStage($measurements, 'ranking_ms');
        $queryTokens = $this->tokens($query);

        // Filename/path context for lexical scoring: a query that names a file
        // ("budget.xlsx", "Rechnung Maerz") must match even when the chunk body
        // never repeats the name. Collected once for all candidate rows.
        $docFields = [];
        foreach ($docMeta as $docId => $doc) {
            $docFields[$docId] = [
                'name' => mb_strtolower((string)$doc->getName()),
                'path' => mb_strtolower((string)$doc->getPath()),
            ];
        }

        $nativeHybrid = isset($rows[0]['_native_rrf']);
        $lexical = $nativeHybrid ? $this->lexicalFileContext($rows, $queryTokens, $docFields) : $this->lexicalBm25($rows, $queryTokens, $docFields);
        $phraseExact = [];
        // Quoted phrases are an explicit exact-match signal. Give them a
        // deterministic boost after BM25/RRF preparation so an exact result
        // wins over a merely semantically related chunk.
        foreach ($this->quotedPhrases($query) as $phrase) {
            foreach ($rows as $i => $row) {
                if (str_contains(mb_strtolower((string)$row['content']), mb_strtolower($phrase))) {
                    $lexical[$i] = ($lexical[$i] ?? 0.0) + 5.0;
                    $phraseExact[$i] = true;
                }
            }
        }

        // A lexical-only request (for example a cloud chat model with a local
        // embedding model disabled) must not deserialize every stored vector.
        // On a large index this was pure CPU work: all scores were zero and
        // the dense rank could not affect the result anyway.
        $dense = [];
        if ($queryVector !== null && !$nativeHybrid) {
            foreach ($rows as $i => $row) {
                $vec = VectorEmbeddingCodec::decode((string)$row['embedding'], count($queryVector));
                if (!is_array($vec)) {
                    continue;
                }
                $similarity = $this->cosine($queryVector, $vec, $queryMagnitude, $row['vector_norm'] ?? null);
                if ($similarity !== null) {
                    $dense[$i] = $similarity;
                }
            }
        }

        // Stable rank orderings for RRF.
        arsort($lexical);
        $lexRank = array_keys($lexical);
        arsort($dense);
        $denseRank = array_keys($dense);
        $lexPos = array_flip($lexRank);
        $densePos = array_flip($denseRank);
        $maxNativeRrf = $nativeHybrid ? max(array_column($rows, '_native_rrf')) : 0.0;

        $results = [];
        foreach ($rows as $i => $row) {
            $lexScore = $lexical[$i] ?? 0.0;
            $denseScore = $dense[$i] ?? 0.0;
            $rrf = $nativeHybrid ? (float)$row['_native_rrf'] : 0.0;
            if ($nativeHybrid && isset($phraseExact[$i])) {
                $rrf += max((float)$maxNativeRrf, 1 / 60) + (1 / 60);
            }
            if (array_key_exists($i, $lexPos)) $rrf += 1 / (60 + $lexPos[$i]);
            if (!$nativeHybrid && array_key_exists($i, $densePos)) $rrf += 1 / (60 + $densePos[$i]);
            $docId = (int)$row['document_id'];
            $doc = $docMeta[$docId] ?? null;
            $results[] = [
                'chunkId' => (int)$row['id'],
                'chunkIndex' => (int)$row['chunk_index'],
                'content' => $row['content'],
                'provenance' => json_decode((string)($row['provenance'] ?? '{}'), true) ?: [],
                'documentId' => $docId,
                'fileId' => $doc !== null ? (int)$doc->getFileId() : 0,
                'docPath' => $doc?->getPath() ?? '',
                'docName' => $doc?->getName() ?? '',
                'score' => $rrf,
                'dense' => $denseScore,
                'lexical' => $lexical[$i] ?? 0.0,
            ];
        }

        usort($results, function ($a, $b) {
            // Deterministic ordering for identical inputs: score first, then
            // chunk id (Issue #143).
            $byScore = $b['score'] <=> $a['score'];
            return $byScore !== 0 ? $byScore : ($a['chunkId'] <=> $b['chunkId']);
        });

        $results = $this->diversify($results, $topK);
        $measurements['ranking_ms'] = (hrtime(true) - $phase) / 1_000_000;
        return $results;
    }

    /**
     * Drop candidate chunks whose document lives outside the folder scope.
     * Paths are stored relative to the user root ("Documents/Example.md"),
     * so the scope matches the folder itself and everything below it.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,Document> $docMeta Already-loaded document metadata
     * @return array<int,array<string,mixed>>
     */
    private function filterByScopePath(array $rows, string $scopePath, array $docMeta): array {
        $prefix = rtrim(trim($scopePath), '/');
        if ($prefix === '') {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            $doc = $docMeta[(int)$row['document_id']] ?? null;
            if ($doc === null) {
                continue;
            }
            if ($doc->getSource() !== \OCA\EvaAi\Db\Document::SOURCE_FILES) continue;
            $path = (string)$doc->getPath();
            if ($path === '') {
                continue;
            }
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Bound repeated chunks per document while preserving a small amount of
     * diversity across relevant documents (Issue #143).
     *
     * After score-sorted ranking, several near-duplicate chunks of the same
     * document can crowd out independently relevant evidence from other
     * documents. When more than one document is relevant we cap each
     * document's contribution (a few chunks at most), so a single document
     * cannot monopolise the context window. When only one document matches,
     * the cap is lifted and the full topK is served - there is no other
     * evidence to diversify with.
     *
     * @param array<int,array<string,mixed>> $ranked
     * @return array<int,array<string,mixed>>
     */
    private function diversify(array $ranked, int $topK): array {
        if ($ranked === [] || $topK <= 1) {
            return array_slice($ranked, 0, $topK);
        }
        $distinctDocs = [];
        foreach ($ranked as $r) {
            $distinctDocs[(int)$r['documentId']] = true;
        }
        $cap = count($distinctDocs) <= 1
            ? $topK
            : max(1, min(3, (int)ceil($topK / 2)));

        $result = [];
        $counts = [];
        foreach ($ranked as $r) {
            $docId = (int)$r['documentId'];
            if (($counts[$docId] ?? 0) >= $cap) {
                continue; // This document already has its fair share.
            }
            $result[] = $r;
            $counts[$docId] = ($counts[$docId] ?? 0) + 1;
            if (count($result) >= $topK) {
                break;
            }
        }
        // Deterministic for identical inputs: candidates arrive already sorted
        // by (score desc, chunk id asc), so the capped walk is reproducible.
        return array_slice($result, 0, $topK);
    }

    /**
     * Build the bounded candidate set for a user + query.
     *
     * On indexes that fit the pool threshold we use every chunk. On larger
     * indexes we build two INDEPENDENT candidate sets and fuse them:
     *
     *  - lexical: chunks matching at least one query token (bounded LIKE scan)
     *  - dense:   the cosine-similarity top of the WHOLE index, computed by
     *             scanning bounded pages instead of sampling one random page,
     *             so semantic-only matches are found wherever they live and
     *             retrieval quality does not degrade as the index grows
     *             (Issue #61)
     *
     * Both sets are merged and deduped by chunk id; dense/BM25 scoring then
     * runs over the union. Memory and latency stay bounded: only the page
     * being scanned plus the running top of the pool are held in memory, and
     * the scan is ordered by chunk id, making the dense candidate set
     * deterministic for identical inputs.
     *
     * @param array|null $queryVector Embedding of the query, or null when the
     *        embedding request failed (dense ranking is then skipped).
     * @return array<int,array<string,mixed>>
     */
    private function loadCandidates(string $userId, string $query, ?array $queryVector, ?float $queryMagnitude, ?VectorGeneration $generation, ?string $scopePath, int $topK, array &$measurements): array {
        $measurements['dense_sql_rows_scanned'] = 0;
        $generationId=$generation?->id;
        $generationState=$generation?->backend==='qdrant'?'delivered':'ready';
        if($generation?->backend==='qdrant'){
            if($this->qdrant===null||$this->generationChunks===null||$this->generations===null)throw new VectorStoreException('qdrant_search_unavailable');
            if ($generation->includeTextPayload) {
                $target=$this->generations->target($userId,$generation->id);
                if($target===null)throw new VectorStoreException('generation_target_missing');
                $guard=function()use($userId,$generation):void{if(!$this->isActiveGenerationCurrent($userId,$generation->id))throw new VectorStoreException('generation_not_active');};
                $store=$this->qdrant->forTarget($target,$guard);
                $limit=min(100,max(20,$topK*4));
                $hits=$store->searchHybrid($generation,$query,$queryVector,$limit,new SearchFilters($scopePath));
                $guard();
                $rows=$this->generationChunks->searchRows($userId,$generation,$hits);
                $byId=[];
                foreach($rows as$row)$byId[(int)$row['id']]=$row;
                $phrases=$this->quotedPhrases($query);
                if($phrases!==[]){
                    $phraseCandidates=$this->chunkMapper->filterChunksByPhrases($userId,$phrases,100,$generation->id,'delivered');
                    $phraseHits=[];
                    foreach($phraseCandidates as$candidate){
                        $text=mb_strtolower((string)$candidate['content']);
                        foreach($phrases as$phrase){
                            if(str_contains($text,mb_strtolower($phrase))){
                                $phraseHits[]=['chunkId'=>(int)$candidate['id'],'revision'=>(int)$candidate['vector_revision']];
                                break;
                            }
                        }
                    }
                    foreach($this->generationChunks->searchRows($userId,$generation,$phraseHits)as$row)$byId[(int)$row['id']]??=$row;
                }
                $rrf=[];
                foreach($hits as$rank=>$hit)$rrf[$hit['chunkId']]=$queryVector===null?1/(60+$rank):(float)$hit['score'];
                foreach($byId as&$row)$row['_native_rrf']=$rrf[(int)$row['id']]??0.0;
                unset($row);
                return array_values($byId);
            }
            $lexical=$this->chunkMapper->filterChunksByTokens($userId,$this->tokens($query),(int)ceil(self::POOL/2),null,$generationId,'delivered');
            $dense=[];
            if($queryVector!==null){
                $target=$this->generations->target($userId,$generation->id);
                if($target===null)throw new VectorStoreException('generation_target_missing');
                $guard=function()use($userId,$generation):void{if(!$this->isActiveGenerationCurrent($userId,$generation->id))throw new VectorStoreException('generation_not_active');};
                $store=$this->qdrant->forTarget($target,$guard);
                $limit=min(100,max(20,$topK*4));
                $hits=$store->search($generation,$queryVector,$limit,new SearchFilters($scopePath));
                $guard();
                $dense=$this->generationChunks->searchRows($userId,$generation,$hits);
            }
            $byId=[];
            foreach($lexical as$row)$byId[(int)$row['id']]=$row;
            foreach($dense as$row)$byId[(int)$row['id']]=$row;
            return array_values(array_slice($byId,0,self::POOL));
        }
        $n = $this->chunkMapper->countForUser($userId,$generationId,$generationState);
        if ($n <= self::POOL) {
            $rows = $this->chunkMapper->chunksForUser($userId,self::POOL,$generationId,$generationState);
            if ($queryVector !== null) $measurements['dense_sql_rows_scanned'] = count($rows);
            return $rows;
        }

        $tokens = $this->tokens($query);

        // Lexical set: bounded prefilter (up to half the pool).
        $lexCap = (int)ceil(self::POOL / 2);
        $lexical = $tokens === [] ? [] : $this->chunkMapper->filterChunksByTokens($userId, $tokens, $lexCap, null, $generationId,$generationState);

        $denseCap = min(self::POOL - count($lexical), $n);
        $denseCap = max(1, $denseCap);
        $denseRows = [];
        if ($queryVector !== null && $denseCap > 0) {
            if ($denseCap < $n) {
                // Full-coverage scan: visit every chunk in fixed pages and keep
                // the best $denseCap cosine scores across the whole index.
                $best = [];
                $keep = $denseCap + self::DENSE_SCAN_PAGE;
                $afterId = 0;
                for ($scanned = 0; $scanned < $n; $scanned += self::DENSE_SCAN_PAGE) {
                    $page = $this->chunkMapper->vectorsForUserAfter($userId, $afterId, self::DENSE_SCAN_PAGE, $generationId,$generationState);
                    if ($page === []) break;
                    $measurements['dense_sql_rows_scanned'] += count($page);
                    $afterId = (int)$page[count($page) - 1]['id'];
                    foreach ($page as $row) {
                        $vec = VectorEmbeddingCodec::decode((string)$row['embedding'], count($queryVector));
                        if (!is_array($vec)) {
                            continue;
                        }
                        $similarity = $this->cosine($queryVector, $vec, $queryMagnitude, $row['vector_norm'] ?? null);
                        if ($similarity !== null) {
                            $best[(int)$row['id']] = $similarity;
                        }
                    }
                    if (count($best) > $keep) {
                        arsort($best);
                        $best = array_slice($best, 0, $denseCap, true);
                    }
                }
                if ($best !== []) {
                    arsort($best);
                    $bestIds = array_keys(array_slice($best, 0, $denseCap, true));
                    foreach (array_chunk($bestIds, 1000) as $idBatch) {
                        foreach ($this->chunkMapper->findChunksByIds($idBatch,$generationId,$generationState) as $row) {
                            $denseRows[] = $row;
                        }
                    }
                }
            } else {
                $denseRows = $this->chunkMapper->chunksForUser($userId,self::POOL,$generationId,$generationState);
            }
        } elseif ($denseCap > 0) {
            // Embedding request failed: keep a deterministic bounded page so
            // retrieval still returns candidates instead of an empty result.
            $denseRows = $this->chunkMapper->chunksForUserPage($userId, $denseCap, 0, $generationId,$generationState);
        }

        // Merge + dedupe by chunk id.
        $byId = [];
        foreach ($lexical as $row) {
            $byId[(int)$row['id']] = $row;
        }
        foreach ($denseRows as $row) {
            if (!isset($byId[(int)$row['id']])) {
                $byId[(int)$row['id']] = $row;
            }
        }
        return array_values(array_slice($byId, 0, self::POOL));
    }

    private function isActiveGenerationCurrent(string $userId,string $generationId): bool {
        try { return $this->generations?->activeGenerationForUser($userId)?->id === $generationId; }
        catch (\Throwable) { return false; }
    }

    /** @return string[] */
    private function tokens(string $text): array {
        $words = $this->wordTokens($text);
        $stop = [
            'der','die','das','den','dem','des','ein','eine','einer','eines','einem','einen',
            'und','oder','aber','auch','im','in','am','an','auf','mit','von','für',
            'zu','nach','bei','aus','über','ist','sind','war','wer','was','wie','wo','wenn',
            'ich','du','er','sie','es','wir','ihr','mir','mich','dir','dich','mein','dein','ihr',
            'more','and','the','of','to','in','is','are','was','for','on','at','with','that','this',
        ];
        $out = [];
        foreach ($words as $tok) {
            if (mb_strlen($tok) < 3 || in_array($tok, $stop, true)) {
                continue;
            }
            $out[] = $tok;
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> Lowercase Unicode word tokens, preserving repeats. */
    private function wordTokens(string $text): array {
        preg_match_all('/\p{L}\p{M}*+[\p{L}\p{M}\p{N}_]*+|\p{N}+/u', mb_strtolower($text), $matches);
        return array_map('strval', $matches[0] ?? []);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $queryTokens
     * @param array<int,array{name:string,path:string}> $docFields lowercased
     *        document name/path per document id, for the filename bonus
     */
    private function lexicalBm25(array $rows, array $queryTokens, array $docFields = []): array {
        // BM25 (k1=1.5, b=0.75) over each chunk treated as its own doc.
        $k1 = 1.5;
        $b = 0.75;
        $lengths = [];
        $scores = [];
        $total = 0;
        // One Unicode regex finds all exact query tokens per body. This avoids
        // rescanning each candidate for every term and excludes partial words.
        $bodyTerms = array_values(array_filter(array_slice($queryTokens, 0, 64), static fn(string $term): bool => mb_strlen($term) <= 128));
        $alternatives = array_map(static fn(string $term): string => preg_quote($term, '~'), $bodyTerms);
        usort($alternatives, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = $alternatives === [] ? null : '~(?<![\\p{L}\\p{M}\\p{N}_])(?:' . implode('|', $alternatives) . ')(?![\\p{L}\\p{M}\\p{N}_])~u';
        $frequencies = [];
        foreach ($rows as $i => $row) {
            $len = $this->tokenCount(strlen($row['content']));
            $lengths[$i] = $len;
            $total += $len;
            $indexedTerms = $row['_eva_lexical_frequencies'] ?? null;
            if (is_array($indexedTerms)) {
                $counts = [];
                foreach ($bodyTerms as $term) {
                    $frequency = (int)($indexedTerms[hash('sha256', $term)] ?? 0);
                    if ($frequency > 0) $counts[$term] = $frequency;
                }
                $frequencies[$i] = $counts;
            } elseif ($pattern === null || preg_match_all($pattern, mb_strtolower((string)$row['content']), $matches) === false) {
                $frequencies[$i] = [];
            } else {
                $frequencies[$i] = array_count_values($matches[0]);
            }
        }
        $N = count($rows);
        $avgdl = $N > 0 ? max(1.0, $total / $N) : 1.0;
        $docRows = [];
        foreach ($rows as $i => $row) {
            $docRows[(int)$row['document_id']][] = $i;
        }

        foreach ($bodyTerms as $term) {
            $df = 0;
            $tfs = [];
            foreach ($frequencies as $i => $counts) {
                $tf = $counts[$term] ?? 0;
                if ($tf > 0) {
                    $tfs[$i] = $tf;
                    $df++;
                }
            }
            // Filename/path matches count toward the document frequency too,
            // so a term that occurs only in file names still gets an idf.
            $fieldMatches = [];
            foreach ($docFields as $docId => $fields) {
                $weight = 0.0;
                if (str_contains($fields['name'], $term)) {
                    $weight = max($weight, 0.5);
                }
                if (str_contains($fields['path'], $term)) {
                    $weight = max($weight, 0.3);
                }
                if ($weight > 0.0) {
                    $fieldMatches[$docId] = $weight;
                    $df++;
                }
            }
            if ($df === 0) {
                continue;
            }
            $idf = log(1 + (($N - $df + 0.5) / max(0.5, $df + 0.5)));
            foreach ($tfs as $i => $tf) {
                $len = $lengths[$i] ?? 1;
                $denom = $tf + $k1 * (1 - $b + $b * $len / $avgdl);
                $scores[$i] = ($scores[$i] ?? 0.0) + $idf * (($k1 + 1) * $tf) / $denom;
            }

            // Filename/path bonus: the token also occurs in the stored file
            // name or path of some documents. Give every chunk of those
            // documents a lexical contribution so "find budget.xlsx" works
            // even though the chunk body never contains the name. The weights
            // stay below a single body occurrence, so a literal body match
            // always outranks a bare filename match. A name match weighs more
            // than a folder path match (the name is the more specific
            // evidence).
            foreach ($fieldMatches as $docId => $weight) {
                foreach ($docRows[$docId] ?? [] as $i) {
                    $scores[$i] = ($scores[$i] ?? 0.0) + $weight * $idf;
                }
            }
        }
        return $scores;
    }

    /** Cheap filename/path signal layered over Qdrant's native full-text BM25. */
    private function lexicalFileContext(array $rows, array $queryTokens, array $docFields): array {
        $scores = [];
        $documentRows = [];
        foreach ($rows as $index => $row) $documentRows[(int)$row['document_id']][] = $index;
        $count = max(1, count($documentRows));
        foreach ($queryTokens as $term) {
            $matches = [];
            foreach ($docFields as $documentId => $fields) {
                $weight = 0.0;
                if (str_contains($fields['name'], $term)) $weight = 0.5;
                if (str_contains($fields['path'], $term)) $weight = max($weight, 0.3);
                if ($weight > 0.0) $matches[$documentId] = $weight;
            }
            $df = count($matches);
            if ($df === 0) continue;
            $idf = log(1 + (($count - $df + 0.5) / ($df + 0.5)));
            foreach ($matches as $documentId => $weight) {
                foreach ($documentRows[$documentId] ?? [] as $index) $scores[$index] = ($scores[$index] ?? 0.0) + $weight * $idf;
            }
        }
        return $scores;
    }

    private function tokenCount(int $chars): int {
        return max(1, (int)ceil($chars / 4));
    }

    /** @return list<string> */
    private function quotedPhrases(string $query): array {
        preg_match_all('/"([^"\n]{2,200})"/u', $query, $matches);
        return array_values(array_unique(array_filter(array_map('trim', $matches[1] ?? []))));
    }

    private function vectorMagnitude(array $vector): ?float {
        if ($vector === []) return null;
        $norm = 0.0;
        foreach ($vector as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) return null;
            $value = (float)$value;
            $norm += $value * $value;
        }
        if (!is_finite($norm) || $norm <= 0.0) return null;
        return sqrt($norm);
    }

    private function cosine(array $a, array $b, ?float $magnitudeA, mixed $magnitudeB = null): ?float {
        if ($a === [] || count($a) !== count($b) || $magnitudeA === null || $magnitudeA <= 0.0) {
            return null;
        }
        $dot = 0.0;
        $knownMagnitudeB = is_numeric($magnitudeB) && is_finite((float)$magnitudeB) && (float)$magnitudeB > 0.0
            ? (float)$magnitudeB : null;
        $nb = 0.0;
        foreach ($a as $i => $value) {
            if (!array_key_exists($i, $b) || !is_numeric($b[$i]) || !is_finite((float)$b[$i])) {
                return null;
            }
            $va = (float)$value;
            $vb = (float)$b[$i];
            $dot += $va * $vb;
            if ($knownMagnitudeB === null) $nb += $vb * $vb;
        }
        $magnitude = $knownMagnitudeB ?? (is_finite($nb) && $nb > 0.0 ? sqrt($nb) : null);
        if ($magnitude === null || !is_finite($magnitude) || $magnitude <= 0.0) {
            return null;
        }
        return $dot / ($magnitudeA * $magnitude);
    }
}
