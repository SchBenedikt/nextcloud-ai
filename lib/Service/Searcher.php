<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\Chunk;

class Searcher {
    private const POOL = 8000;

    /** Rows scanned per SQL page while computing full-coverage dense scores. */
    private const DENSE_SCAN_PAGE = 2000;

    public function __construct(
        private Ollama $ollama,
        private ChunkMapper $chunkMapper,
        private DocumentMapper $documentMapper,
        private AppConfig $config
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
    public function search(string $userId, string $query, int $topK, ?string $scopePath = null): array {
        $topK = max(1, min($topK, (int)AppConfig::LIMITS['top_k'][1]));
        if (trim($query) === '') {
            return [];
        }
        if ($this->config->get('chat_provider') === 'groq' && $this->chunkMapper->countForUser($userId) === 0) return [];
        [$queryVec, $err] = $this->ollama->embedQuery([$query], $userId);
        $queryVector = $err === null && is_array($queryVec) && isset($queryVec[0]) ? $queryVec[0] : null;
        $rows = $this->loadCandidates($userId, $query, $queryVector);
        if ($scopePath !== null && trim($scopePath) !== '') {
            $rows = $this->filterByScopePath($userId, $rows, $scopePath);
        }

        $queryTokens = $this->tokens($query);

        // Filename/path context for lexical scoring: a query that names a file
        // ("budget.xlsx", "Rechnung Maerz") must match even when the chunk body
        // never repeats the name. Collected once for all candidate rows.
        $docIds = [];
        foreach ($rows as $row) {
            $docIds[(int)$row['document_id']] = true;
        }
        $docMeta = [];
        $docs = $this->documentMapper->findByIds(array_keys($docIds));
        foreach ($docs as $d) {
            $docMeta[(int)$d->getId()] = $d;
        }
        $docFields = [];
        foreach ($docMeta as $docId => $doc) {
            $docFields[$docId] = [
                'name' => mb_strtolower((string)$doc->getName()),
                'path' => mb_strtolower((string)$doc->getPath()),
            ];
        }

        $lexical = $this->lexicalBm25($rows, $queryTokens, $docFields);

        $dense = [];
        foreach ($rows as $i => $row) {
            $vec = json_decode($row['embedding'], true);
            if (is_array($vec) && !empty($vec)) {
                if ($queryVector !== null) {
                    $cos = $this->cosine($queryVector, $vec);
                    $dense[$i] = $cos;
                } else {
                    $dense[$i] = 0.0;
                }
            } else {
                $dense[$i] = 0.0;
            }
        }

        // Stable rank orderings for RRF.
        arsort($lexical);
        $lexRank = array_keys($lexical);
        arsort($dense);
        $denseRank = array_keys($dense);
        $lexPos = array_flip($lexRank);
        $densePos = array_flip($denseRank);

        $results = [];
        foreach ($rows as $i => $row) {
            $lexScore = $lexical[$i] ?? 0.0;
            $denseScore = $dense[$i] ?? 0.0;
            $rrf = 0.0;
            if (array_key_exists($i, $lexPos)) {
                $rrf += 1 / (60 + $lexPos[$i]);
            }
            if (array_key_exists($i, $densePos)) {
                $rrf += 1 / (60 + $densePos[$i]);
            }
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

        return $this->diversify($results, $topK);
    }

    /**
     * Drop candidate chunks whose document lives outside the folder scope.
     * Paths are stored relative to the user root ("Documents/Example.md"),
     * so the scope matches the folder itself and everything below it.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function filterByScopePath(string $userId, array $rows, string $scopePath): array {
        $prefix = rtrim(trim($scopePath), '/');
        if ($prefix === '') {
            return $rows;
        }
        $docIds = [];
        foreach ($rows as $row) {
            $docIds[(int)$row['document_id']] = true;
        }
        if ($docIds === []) {
            return [];
        }
        $paths = [];
        foreach ($this->documentMapper->findByIds(array_keys($docIds)) as $doc) {
            $paths[(int)$doc->getId()] = (string)$doc->getPath();
        }
        $out = [];
        foreach ($rows as $row) {
            $path = $paths[(int)$row['document_id']] ?? '';
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
    private function loadCandidates(string $userId, string $query, ?array $queryVector): array {
        $n = $this->chunkMapper->countForUser($userId);
        if ($n <= self::POOL) {
            return $this->chunkMapper->chunksForUser($userId);
        }

        $tokens = $this->tokens($query);

        // Lexical set: bounded prefilter (up to half the pool).
        $lexCap = (int)ceil(self::POOL / 2);
        $lexical = $tokens === [] ? [] : $this->chunkMapper->filterChunksByTokens($userId, $tokens, $lexCap);

        $denseCap = min(self::POOL - count($lexical), $n);
        $denseCap = max(1, $denseCap);
        $denseRows = [];
        if ($queryVector !== null && $denseCap > 0) {
            if ($denseCap < $n) {
                // Full-coverage scan: visit every chunk in fixed pages and keep
                // the best $denseCap cosine scores across the whole index.
                $best = [];
                $keep = $denseCap + self::DENSE_SCAN_PAGE;
                for ($offset = 0; $offset < $n; $offset += self::DENSE_SCAN_PAGE) {
                    $page = $this->chunkMapper->chunksForUserPage($userId, self::DENSE_SCAN_PAGE, $offset);
                    foreach ($page as $row) {
                        $vec = json_decode($row['embedding'], true);
                        if (!is_array($vec) || $vec === []) {
                            continue;
                        }
                        $best[(int)$row['id']] = $this->cosine($queryVector, $vec);
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
                        foreach ($this->chunkMapper->findChunksByIds($idBatch) as $row) {
                            $denseRows[] = $row;
                        }
                    }
                }
            } else {
                $denseRows = $this->chunkMapper->chunksForUser($userId);
            }
        } elseif ($denseCap > 0) {
            // Embedding request failed: keep a deterministic bounded page so
            // retrieval still returns candidates instead of an empty result.
            $denseRows = $this->chunkMapper->chunksForUserPage($userId, $denseCap, 0);
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

    /** @return string[] */
    private function tokens(string $text): array {
        $text = mb_strtolower($text);
        preg_match_all('/\p{L}\p{M}*+[\p{L}\p{M}\p{N}_]*+|\p{N}+/u', $text, $m);
        $stop = [
            'der','die','das','den','dem','des','ein','eine','einer','eines','einem','einen',
            'und','oder','aber','auch','im','in','am','an','auf','mit','von','für',
            'zu','nach','bei','aus','über','ist','sind','war','wer','was','wie','wo','wenn',
            'ich','du','er','sie','es','wir','ihr','mir','mich','dir','dich','mein','dein','ihr',
            'more','and','the','of','to','in','is','are','was','for','on','at','with','that','this',
        ];
        $out = [];
        foreach ($m[0] as $tok) {
            $tok = (string)$tok;
            if (mb_strlen($tok) < 3 || in_array($tok, $stop, true)) {
                continue;
            }
            $out[] = $tok;
        }
        return array_values(array_unique($out));
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
        // Lowercase every chunk once up front: document frequency and term
        // frequency then come from a single substr_count pass per term instead
        // of an extra mb_stripos pre-pass plus a repeated mb_strtolower per
        // (term, row) pair. Same scores, roughly half the scanning work on
        // large candidate pools.
        $lowered = [];
        foreach ($rows as $i => $row) {
            $len = $this->tokenCount(strlen($row['content']));
            $lengths[$i] = $len;
            $total += $len;
            $lowered[$i] = mb_strtolower($row['content']);
        }
        $N = count($rows);
        $avgdl = $N > 0 ? max(1.0, $total / $N) : 1.0;
        $docRows = [];
        foreach ($rows as $i => $row) {
            $docRows[(int)$row['document_id']][] = $i;
        }

        foreach ($queryTokens as $term) {
            $df = 0;
            $tfs = [];
            foreach ($lowered as $i => $lower) {
                $tf = substr_count($lower, $term);
                if ($tf !== 0) {
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

    private function tokenCount(int $chars): int {
        return max(1, (int)ceil($chars / 4));
    }

    private function cosine(array $a, array $b): float {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $va = (float)$a[$i];
            $vb = (float)$b[$i];
            $dot += $va * $vb;
            $na += $va * $va;
            $nb += $vb * $vb;
        }
        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
