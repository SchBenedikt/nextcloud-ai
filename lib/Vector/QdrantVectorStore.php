<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Optional dense index. It is not selected by Searcher or indexing yet. */
class QdrantVectorStore implements VectorStore {
    private const INDEXES = ['user_id' => 'keyword', 'generation_id' => 'keyword', 'document_id' => 'integer', 'source' => 'keyword', 'scope_paths' => 'keyword'];
    public function __construct(private QdrantConnection $connection) {}

    public function health(): array {
        try {
            $data = $this->connection->request('get', '/', null, false, 3);
            $version = $data['version'] ?? '';
            if (!is_string($version) || !preg_match('/^1\.\d+\.\d+(?:[-+].*)?$/D', $version) || version_compare($version, '1.16.0', '<')) {
                return ['available' => false, 'code' => 'unsupported_version'];
            }
            return ['available' => true, 'code' => 'api_reachable'];
        } catch (VectorStoreException $e) { return ['available' => false, 'code' => $e->reason]; }
        catch (\Throwable) { return ['available' => false, 'code' => 'unavailable']; }
    }

    /** Explicit preparation, not a constructor or installation side effect. */
    public function prepare(VectorGeneration $generation): void {
        $health = $this->health();
        if (!$health['available']) throw new VectorStoreException($health['code']);
        $path = $this->collection($generation);
        $data = $this->connection->request('get', $path, null, true);
        if ($data === null) {
            try { $this->ok($this->connection->request('put', $path, ['vectors' => ['dense' => ['size' => $generation->dimension, 'distance' => 'Cosine']]])); }
            catch (VectorStoreException $e) {
                // Another explicit preparer may have won collection creation.
                if ($e->reason !== 'http_409') throw $e;
            }
            $data = $this->connection->request('get', $path);
        }
        $this->ok($data);
        $dense = $data['result']['config']['params']['vectors']['dense'] ?? null;
        if (!is_array($dense) || ($dense['size'] ?? null) !== $generation->dimension || ($dense['distance'] ?? '') !== 'Cosine') {
            throw new VectorStoreException('collection_identity_mismatch');
        }
        $schema = $data['result']['payload_schema'] ?? [];
        if (!is_array($schema)) throw new VectorStoreException('invalid_payload_schema');
        foreach (self::INDEXES as $field => $type) {
            if (isset($schema[$field])) {
                if (($schema[$field]['data_type'] ?? '') !== $type) throw new VectorStoreException('payload_index_mismatch');
                continue;
            }
            $this->completed($this->connection->request('put', $path . '/index?wait=true&ordering=strong', ['field_name' => $field, 'field_schema' => $type]));
        }
    }

    public function upsert(VectorGeneration $generation, array $points): void {
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
            new SearchFilters($point['path']); // Canonical scope syntax, not arbitrary filter JSON.
            $generation->validateVector($point['vector']);
            $elements += count($point['vector']);
            if ($elements > 250000) throw new VectorStoreException('batch_too_large');
            $ids[$point['id']] = true;
            $prepared[] = ['id' => $point['id'], 'vector' => ['dense' => array_map('floatval', $point['vector'])],
                'payload' => ['user_id' => $this->tenant($generation), 'generation_id' => $generation->id,
                    'document_id' => $point['document_id'], 'file_id' => $point['file_id'], 'chunk_index' => $point['chunk_index'],
                    'source' => $point['source'], 'content_hash' => $point['content_hash'], 'scope_paths' => $this->scopes($point['path'])]];
        }
        // Validate the entire batch before the first network request.
        $this->completed($this->connection->request('put', $this->collection($generation) . '/points?wait=true&ordering=strong', ['points' => $prepared]));
    }

    public function deleteDocument(VectorGeneration $generation, int $documentId): void {
        if ($documentId < 1) throw new VectorStoreException('invalid_document');
        $filter = $this->filter($generation, new SearchFilters(documentIds: [$documentId]));
        $data = $this->connection->request('post', $this->collection($generation) . '/points/delete?wait=true&ordering=strong', ['filter' => $filter], true);
        if ($data !== null) $this->completed($data);
    }

    public function search(VectorGeneration $generation, array $queryVector, int $topK, SearchFilters $filters): array {
        $generation->validateVector($queryVector);
        if ($topK < 1 || $topK > 100) throw new VectorStoreException('invalid_limit');
        $data = $this->connection->request('post', $this->collection($generation) . '/points/query?timeout=3',
            ['query' => array_map('floatval', $queryVector), 'using' => 'dense', 'limit' => $topK,
                'filter' => $this->filter($generation, $filters), 'with_vector' => false,
                'with_payload' => ['user_id', 'generation_id', 'document_id', 'source', 'scope_paths']], false, 5);
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
                || !is_int($payload['document_id'] ?? null) || $payload['document_id'] < 1
                || !in_array($payload['source'] ?? null, ['files', 'mail', 'talk'], true)
                || ($filters->source !== null && $payload['source'] !== $filters->source)
                || ($filters->documentIds !== [] && !in_array($payload['document_id'], $filters->documentIds, true))
                || ($filters->scopePath !== null && $filters->scopePath !== '' && (!is_array($payload['scope_paths'] ?? null) || !in_array(hash('sha256', $filters->scopePath), $payload['scope_paths'], true)))) {
                // Do not quietly drop a cross-tenant response and conceal a violated invariant.
                throw new VectorStoreException('search_isolation_violation');
            }
            $seen[$id] = true;
            $results[] = ['chunkId' => $id, 'score' => (float)$score];
        }
        return $results;
    }

    private function filter(VectorGeneration $generation, SearchFilters $filters): array {
        $must = [['key' => 'user_id', 'match' => ['value' => $this->tenant($generation)]],
            ['key' => 'generation_id', 'match' => ['value' => $generation->id]]];
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
    private function collection(VectorGeneration $generation): string { return '/collections/' . $generation->collection(); }
    private function tenant(VectorGeneration $generation): string { return hash('sha256', $generation->userId); }
    private function ok(?array $data): void { if (($data['status'] ?? null) !== 'ok') throw new VectorStoreException('invalid_operation_response'); }
    private function completed(?array $data): void {
        $this->ok($data);
        if (($data['result']['status'] ?? '') !== 'completed') throw new VectorStoreException('operation_not_completed');
    }
}
