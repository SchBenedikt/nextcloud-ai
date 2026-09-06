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
    public function search(string $userId, string $query, int $topK, array $documentIds = [], string $folder = ''): array {
        $topK = max(1, min($topK, (int)AppConfig::LIMITS['top_k'][1]));
        if (trim($query) === '') {
            return [];
        }
        [$queryVec, $err] = $this->ollama->embedQuery([$query], $userId);
        $rows = $this->loadCandidates($userId, $query, $err === null ? ($queryVec[0] ?? []) : [], $documentIds, $folder);

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
                    unset($dense[$i]);
                }
            } else {
                unset($dense[$i]);
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
            if ($denseScore > 0 && array_key_exists($i, $densePos)) {
                $rrf += 1 / (60 + $densePos[$i]);
            }
            $docId = (int)$row['document_id'];
            $doc = $docMeta[$docId] ?? null;
            $results[] = [
                'chunkId' => (int)$row['id'],
                'provenance' => json_decode($row['provenance'] ?? 'null', true) ?: [],
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
            return ($b['score'] <=> $a['score']) ?: ($a['chunkId'] <=> $b['chunkId']);
        });

        // Bound one document's influence while preserving deterministic ranking.
        $selected = [];
        $perDoc = [];
        foreach ($results as $hit) {
            if ($hit['score'] <= 0 || ($perDoc[$hit['documentId']] ?? 0) >= max(2, (int)ceil($topK / 2))) { continue; }
            $selected[] = $hit;
            $perDoc[$hit['documentId']] = ($perDoc[$hit['documentId']] ?? 0) + 1;
            if (count($selected) === $topK) { break; }
        }
        return $selected;
    }

    /** Full-coverage dense scan with bounded top-candidate retention. */
    private function loadCandidates(string $userId, string $query, array $queryVector, array $documentIds = [], string $folder = ''): array {
        $lexical = $this->chunkMapper->filterChunksByTokens($userId, $this->tokens($query), 256, $documentIds, $folder);
        $heap = new \SplPriorityQueue();
        $after = 0;
        do {
            $page = $this->chunkMapper->scanForUser($userId, $after, 512, $documentIds, $folder);
            foreach ($page as $row) {
                $after = max($after, (int)$row['id']);
                $vector = json_decode($row['embedding'], true);
                if ($queryVector === [] || !is_array($vector) || count($vector) !== count($queryVector)) {
                    continue;
                }
                $score = $this->cosine($queryVector, $vector);
                if ($score <= 0) { continue; }
                // Negative priority keeps the worst retained candidate on top.
                $heap->insert($row, [-$score, -(int)$row['id']]);
                if ($heap->count() > 256) { $heap->extract(); }
            }
        } while (count($page) === 512);
        $byId = [];
        foreach ($lexical as $row) { $byId[(int)$row['id']] = $row; }
        foreach ($heap as $row) { $byId[(int)$row['id']] = $row; }
        return array_values($byId);
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
        if (count($a) !== count($b)) { return 0.0; }
        $n = count($a);
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
