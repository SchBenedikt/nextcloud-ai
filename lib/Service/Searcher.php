<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\Chunk;

class Searcher {
    private const POOL = 8000;

    public function __construct(
        private Ollama $ollama,
        private ChunkMapper $chunkMapper,
        private DocumentMapper $documentMapper,
        private AppConfig $config
    ) {
    }

    /**
     * @return array<int,array{chunk:array,doc:?array,cosine:float,lexical:float,score:float}>
     */
    public function search(string $userId, string $query, int $topK): array {
        if (trim($query) === '') {
            return [];
        }
        [$queryVec, $err] = $this->ollama->embedQuery([$query]);
        $rows = $this->loadCandidates($userId, $query);

        $queryTokens = $this->tokens($query);
        $lexical = $this->lexicalBm25($rows, $queryTokens);

        $dense = [];
        foreach ($rows as $i => $row) {
            $vec = json_decode($row['embedding'], true);
            if (is_array($vec) && !empty($vec)) {
                if ($err === null && is_array($queryVec) && isset($queryVec[0])) {
                    $cos = $this->cosine($queryVec[0], $vec);
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

        $docIds = [];
        foreach ($rows as $row) {
            $docIds[(int)$row['document_id']] = true;
        }
        $docMeta = [];
        // Batched lookup via findEntities is not ideal; use mapper query.
        $docs = $this->documentMapper->findByIds(array_keys($docIds));
        foreach ($docs as $d) {
            $docMeta[(int)$d->getId()] = $d;
        }

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
            return ($b['score'] <=> $a['score']);
        });

        return array_slice($results, 0, $topK);
    }

    /**
     * Build the bounded candidate set for a user + query.
     *
     * On indexes that fit the pool threshold we use every chunk. On larger
     * indexes we build two INDEPENDENT candidate sets and fuse them:
     *
     *  - lexical: chunks matching at least one query token (bounded LIKE scan)
     *  - dense:   a bounded, token-independent page of chunks so semantic-only
     *             matches are NOT gated by lexical overlap (Issue #13)
     *
     * Both sets are merged and deduped by chunk id; dense/BM25 scoring then
     * runs over the union, keeping retrieval quality stable above the
     * threshold. Empty-token queries stay bounded too (see ChunkMapper).
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadCandidates(string $userId, string $query): array {
        $n = $this->chunkMapper->countForUser($userId);
        if ($n <= self::POOL) {
            return $this->chunkMapper->chunksForUser($userId);
        }

        $tokens = $this->tokens($query);

        // Lexical set: bounded prefilter (up to half the pool).
        $lexCap = (int)ceil(self::POOL / 2);
        $lexical = $tokens === [] ? [] : $this->chunkMapper->filterChunksByTokens($userId, $tokens, $lexCap);

        // Dense set: independent bounded sample (rest of the pool), starting at
        // a query-derived offset so the sample is stable per query.
        $denseCap = self::POOL - count($lexical);
        $denseCap = max(1, min($denseCap, $n));
        $offset = 0;
        if ($denseCap < $n) {
            $offset = (abs(crc32($query)) % max(1, $n - $denseCap));
        }
        $dense = $this->chunkMapper->chunksForUserPage($userId, $denseCap, $offset);

        // Merge + dedupe by chunk id.
        $byId = [];
        foreach ($lexical as $row) {
            $byId[(int)$row['id']] = $row;
        }
        foreach ($dense as $row) {
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

    /** @param array<int,array<string,mixed>> $rows @param string[] $queryTokens */
    private function lexicalBm25(array $rows, array $queryTokens): array {
        // BM25 (k1=1.5, b=0.75) over each chunk treated as its own doc.
        $k1 = 1.5;
        $b = 0.75;
        $avgdl = 1.0;
        $lengths = [];
        $scores = [];
        $total = 0;
        foreach ($rows as $i => $row) {
            $len = $this->tokenCount(strlen($row['content']));
            $lengths[$i] = $len;
            $total += $len;
        }
        $N = count($rows);
        $avgdl = $N > 0 ? max(1.0, $total / $N) : 1.0;

        foreach ($queryTokens as $term) {
            $df = 0;
            foreach ($rows as $row) {
                if (mb_stripos($row['content'], $term) !== false) {
                    $df++;
                }
            }
            $idf = log(1 + (($N - $df + 0.5) / max(0.5, $df + 0.5)));
            foreach ($rows as $i => $row) {
                $tf = substr_count(mb_strtolower($row['content']), $term);
                if ($tf === 0) {
                    continue;
                }
                $len = $lengths[$i] ?? 1;
                $denom = $tf + $k1 * (1 - $b + $b * $len / $avgdl);
                $scores[$i] = ($scores[$i] ?? 0.0) + $idf * (($k1 + 1) * $tf) / $denom;
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
