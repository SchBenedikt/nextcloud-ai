<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Offline, content-free ranking evaluation; never opens a network connection. */
final class RetrievalBenchmark {
    private const MAX_LINE = 1048576;
    private const TOP_K = 20;

    public function dataset(string $directory): array {
        $manifest = $this->jsonFile($directory . '/manifest.json');
        $queries = $this->jsonFile($directory . '/queries.json');
        $corpus = $directory . '/chunks.jsonl';
        if (($manifest['version'] ?? null) !== 1 || !is_file($corpus)
            || !is_array($queries) || !array_is_list($queries) || count($queries) < 1 || count($queries) > 1000) {
            throw new \RuntimeException('Invalid benchmark manifest or query list.');
        }
        $hash = hash_file('sha256', $corpus);
        if (!is_string($manifest['corpus_sha256'] ?? null) || !hash_equals($manifest['corpus_sha256'], $hash)) {
            throw new \RuntimeException('Corpus hash does not match the frozen manifest.');
        }
        $this->validateQueries($queries);
        return ['manifest' => $manifest, 'queries' => $queries, 'corpus' => $corpus,
            'corpus_sha256' => $hash, 'queries_sha256' => hash_file('sha256', $directory . '/queries.json')];
    }

    public function validateQueries(array $queries): void {
        if (!array_is_list($queries) || count($queries) < 1 || count($queries) > 1000) throw new \RuntimeException('Invalid query list size.');
        $ids = [];
        foreach ($queries as $query) {
            if (!is_array($query) || !is_string($query['id'] ?? null) || $query['id'] === '' || isset($ids[$query['id']])
                || !is_string($query['user'] ?? null) || $query['user'] === ''
                || !is_array($query['relevance'] ?? null) || $query['relevance'] === [] || count($query['relevance']) > 1000
                || !is_string($query['category'] ?? null)
                || !in_array($query['category'], ['semantic', 'exact', 'mixed', 'phrase', 'diversification', 'scope', 'tenant'], true)
                || (isset($query['scope']) && (!is_string($query['scope']) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $query['scope'])))) {
                throw new \RuntimeException('Invalid or duplicate benchmark query.');
            }
            $this->vector($query['vector'] ?? null);
            new \OCA\EvaAi\Vector\SearchFilters($query['scope'] ?? null);
            foreach ($query['relevance'] as $id => $grade) {
                if (!ctype_digit((string)$id) || (int)$id < 1 || !is_int($grade) || $grade < 1 || $grade > 3) {
                    throw new \RuntimeException('Relevance labels require positive chunk IDs and grades 1–3.');
                }
            }
            $ids[$query['id']] = true;
        }
    }

    /** Bounded-memory exact cosine oracle. Ties are broken by ascending chunk ID. */
    public function exact(array $dataset): array {
        $runs = [];
        foreach ($dataset['queries'] as $query) {
            $start = hrtime(true);
            $top = [];
            foreach ($this->chunks($dataset['corpus']) as $chunk) {
                if (!$this->eligible($chunk, $query)) continue;
                if (count($query['vector']) !== count($chunk['vector'])) {
                    throw new \RuntimeException('Query and corpus vector dimensions differ.');
                }
                $top[] = ['id' => $chunk['id'], 'score' => $this->cosine($query['vector'], $chunk['vector'])];
                if (count($top) > self::TOP_K * 2) $top = $this->best($top);
            }
            $top = $this->best($top);
            $runs[] = ['query_id' => $query['id'], 'ranking' => array_column($top, 'id'),
                'corpus_sha256' => $dataset['corpus_sha256'], 'queries_sha256' => $dataset['queries_sha256'],
                'duration_ms' => (hrtime(true) - $start) / 1_000_000];
        }
        return $runs;
    }

    public function readRuns(string $file): array {
        if (!is_file($file) || filesize($file) > 8388608) throw new \RuntimeException('Missing or oversized benchmark run file.');
        $runs = [];
        foreach ($this->lines($file) as $row) {
            if (count($runs) >= 1000) throw new \RuntimeException('Too many run rows.');
            $runs[] = $row;
        }
        return $runs;
    }

    public function evaluate(array $dataset, array $runs, string $backend): array {
        if ($backend === '' || strlen($backend) > 100) throw new \RuntimeException('Invalid backend label.');
        $byQuery = []; $needed = [];
        foreach ($dataset['queries'] as $query) {
            foreach ($query['relevance'] as $id => $_) $needed[(int)$id] = true;
        }
        foreach ($runs as $run) {
            $id = $run['query_id'] ?? null;
            if (!is_string($id) || isset($byQuery[$id]) || !is_array($run['ranking'] ?? null)
                || ($run['corpus_sha256'] ?? null) !== $dataset['corpus_sha256'] || ($run['queries_sha256'] ?? null) !== $dataset['queries_sha256']
                || !array_is_list($run['ranking']) || count($run['ranking']) > 1000
                || !is_numeric($run['duration_ms'] ?? null) || !is_finite((float)$run['duration_ms']) || $run['duration_ms'] < 0) {
                throw new \RuntimeException('Invalid, duplicate or unmeasured run row.');
            }
            foreach ($run['ranking'] as $chunkId) {
                if (!is_int($chunkId) || $chunkId < 1) throw new \RuntimeException('Run ranking IDs must be positive integers.');
            }
            $run['ranking'] = array_slice($run['ranking'], 0, self::TOP_K);
            foreach ($run['ranking'] as $chunkId) $needed[$chunkId] = true;
            $byQuery[$id] = $run;
        }
        if (count($byQuery) !== count($dataset['queries'])) throw new \RuntimeException('Every query needs exactly one run row.');
        if (count($needed) > 100000) throw new \RuntimeException('Evaluation metadata is limited to 100,000 referenced chunks.');
        $metadata = []; $chunkCount = 0;
        foreach ($this->chunks($dataset['corpus']) as $chunk) {
            $chunkCount++;
            if (isset($needed[$chunk['id']])) {
                unset($chunk['vector']);
                $metadata[$chunk['id']] = $chunk;
            }
        }
        $rows = []; $latencies = [];
        foreach ($dataset['queries'] as $query) {
            $run = $byQuery[$query['id']] ?? null;
            if ($run === null) throw new \RuntimeException('Missing query run.');
            foreach ($query['relevance'] as $id => $_) {
                if (!isset($metadata[$id]) || !$this->eligible($metadata[$id], $query)) {
                    throw new \RuntimeException('Relevance labels reference missing or out-of-scope chunks.');
                }
            }
            $ranking = $run['ranking']; $leaks = 0; $scopeErrors = 0; $unknown = 0;
            foreach ($ranking as $id) {
                if (!isset($metadata[$id])) $unknown++;
                elseif ($metadata[$id]['user'] !== $query['user']) $leaks++;
                elseif (!$this->eligible($metadata[$id], $query)) $scopeErrors++;
            }
            $scores = ['cross_tenant_leakage' => $leaks, 'scope_violations' => $scopeErrors, 'unknown_chunks' => $unknown];
            foreach ([5, 10, 20] as $k) {
                $seen = []; $hits = 0; $docs = []; $relevantDocs = []; $foundDocs = [];
                foreach ($query['relevance'] as $id => $_) $relevantDocs[$metadata[$id]['document_id']] = true;
                foreach (array_slice($ranking, 0, $k) as $id) {
                    if (isset($seen[$id])) continue;
                    $seen[$id] = true;
                    if (isset($metadata[$id]) && $this->eligible($metadata[$id], $query)) {
                        $docs[$metadata[$id]['document_id']] = true;
                        if (isset($relevantDocs[$metadata[$id]['document_id']])) $foundDocs[$metadata[$id]['document_id']] = true;
                        if (isset($query['relevance'][$id])) $hits++;
                    }
                }
                $scores['recall@' . $k] = $hits / count($query['relevance']);
                $scores['precision@' . $k] = $hits / $k;
                $scores['distinct_documents@' . $k] = count($docs);
                $scores['source_document_recall@' . $k] = count($foundDocs) / count($relevantDocs);
                $returned = count(array_slice($ranking, 0, $k));
                $scores['duplicate_chunk_rate@' . $k] = $returned === 0 ? 0.0 : ($returned - count($seen)) / $returned;
            }
            $dcg = 0.0; $rr = 0.0; $seen = [];
            foreach (array_slice($ranking, 0, 10) as $position => $id) {
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                $grade = $query['relevance'][$id] ?? 0;
                $dcg += (2 ** $grade - 1) / log($position + 2, 2);
                if ($grade > 0 && $rr === 0.0) $rr = 1 / ($position + 1);
            }
            $ideal = array_values($query['relevance']); rsort($ideal); $idcg = 0.0;
            foreach (array_slice($ideal, 0, 10) as $position => $grade) $idcg += (2 ** $grade - 1) / log($position + 2, 2);
            $scores['mrr@10'] = $rr; $scores['ndcg@10'] = $dcg / $idcg;
            $rows[] = ['query_id' => $query['id'], 'category' => $query['category'], 'ranking' => $ranking, 'metrics' => $scores, 'duration_ms' => (float)$run['duration_ms']];
            $latencies[] = (float)$run['duration_ms'];
        }
        $mean = [];
        foreach ($rows as $row) foreach ($row['metrics'] as $key => $value) $mean[$key] = ($mean[$key] ?? 0) + $value / count($rows);
        sort($latencies);
        $percentiles = [];
        foreach ([50, 95, 99] as $percentile) $percentiles['p' . $percentile . '_ms'] = $latencies[max(0, (int)ceil(count($latencies) * $percentile / 100) - 1)];
        $violations = array_sum(array_map(static fn(array $row) => $row['metrics']['cross_tenant_leakage'] + $row['metrics']['scope_violations'] + $row['metrics']['unknown_chunks'], $rows));
        if (hash_file('sha256', $dataset['corpus']) !== $dataset['corpus_sha256']) throw new \RuntimeException('Corpus changed during benchmark evaluation.');
        return ['version' => 1, 'backend' => $backend, 'corpus_sha256' => $dataset['corpus_sha256'], 'queries_sha256' => $dataset['queries_sha256'],
            'chunks' => $chunkCount, 'query_count' => count($rows), 'isolation_passed' => $violations === 0, 'isolation_violations' => $violations,
            'mean_metrics' => $mean, 'latency' => $percentiles, 'peak_memory_bytes' => memory_get_peak_usage(true),
            'php_version' => PHP_VERSION, 'queries' => $rows];
    }

    private function jsonFile(string $file): array {
        if (!is_file($file) || filesize($file) > 8388608) throw new \RuntimeException('Missing or oversized benchmark JSON file.');
        $data = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Expected benchmark JSON object or array.');
        return $data;
    }

    private function lines(string $file): \Generator {
        $handle = fopen($file, 'rb');
        if ($handle === false) throw new \RuntimeException('Cannot open benchmark JSONL file.');
        try {
            while (($line = fgets($handle, self::MAX_LINE + 1)) !== false) {
                if (strlen($line) >= self::MAX_LINE || trim($line) === '') throw new \RuntimeException('Invalid or oversized JSONL record.');
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($row)) throw new \RuntimeException('Expected JSONL object.');
                yield $row;
            }
            if (!feof($handle)) throw new \RuntimeException('Benchmark file read failed.');
        } finally { fclose($handle); }
    }

    private function chunks(string $file): \Generator {
        $previous = 0;
        foreach ($this->lines($file) as $row) {
            if (!is_int($row['id'] ?? null) || $row['id'] <= $previous || !is_int($row['document_id'] ?? null) || $row['document_id'] < 1
                || !is_string($row['user'] ?? null) || $row['user'] === '' || !is_string($row['path'] ?? null)
                || $row['path'] === '') {
                throw new \RuntimeException('Corpus needs ascending unique IDs, document IDs, user IDs and paths.');
            }
            $source = $row['source'] ?? 'files';
            if ($source === 'files') {
                new \OCA\EvaAi\Vector\SearchFilters($row['path']);
            } elseif (!in_array($source, ['mail', 'talk'], true) || !str_starts_with($row['path'], $source . '://')
                || strlen($row['path']) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $row['path'])) {
                throw new \RuntimeException('Invalid corpus source/path.');
            }
            $this->vector($row['vector'] ?? null);
            $previous = $row['id'];
            yield $row;
        }
    }

    private function vector(mixed $vector): void {
        if (!is_array($vector) || !array_is_list($vector) || count($vector) < 1 || count($vector) > 16384) throw new \RuntimeException('Invalid vector dimension.');
        $norm = 0.0;
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) throw new \RuntimeException('Vectors must contain finite numbers.');
            $norm += $value * $value;
        }
        if ($norm <= 0 || !is_finite($norm)) throw new \RuntimeException('Vectors must have finite nonzero magnitude.');
    }

    private function eligible(array $chunk, array $query): bool {
        if ($chunk['user'] !== $query['user']) return false;
        $scope = trim($query['scope'] ?? '', '/');
        if ($scope !== '' && ($chunk['source'] ?? 'files') !== 'files') return false;
        $path = trim($chunk['path'], '/');
        return $scope === '' || $path === $scope || str_starts_with($path, $scope . '/');
    }

    private function cosine(array $a, array $b): float {
        $dot = 0.0; $aa = 0.0; $bb = 0.0;
        foreach ($a as $i => $value) { $dot += $value * $b[$i]; $aa += $value * $value; $bb += $b[$i] * $b[$i]; }
        return $dot / sqrt($aa) / sqrt($bb);
    }

    private function best(array $rows): array {
        usort($rows, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
        return array_slice($rows, 0, self::TOP_K);
    }
}
