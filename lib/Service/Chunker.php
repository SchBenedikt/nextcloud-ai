<?php

declare(strict_types=1);

namespace OCA\RagChat\Service;

class Chunker {
    public function __construct(private AppConfig $config) {
    }

    /**
     * Split raw text into overlapping sentence-boundary chunks.
     * @return array<int,array{content:string,tokens:int}>
     */
    public function chunk(string $text): array {
        if (trim($text) === '') {
            return [];
        }
        $chunkSize = $this->config->getInt('chunk_size', 900);
        $overlap = $this->config->getInt('chunk_overlap', 120);
        if ($overlap >= $chunkSize) {
            $overlap = max(0, (int)($chunkSize / 4));
        }

        // Normalise whitespace runs but keep line structure.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $sentences = $this->splitSentences($text);
        if (empty($sentences)) {
            return [];
        }

        $chunks = [];
        $current = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($current . $sentence) <= $chunkSize) {
                $current .= $sentence;
                continue;
            }
            if ($current !== '') {
                $chunks[] = trim($current);
            }
            // A single sentence longer than the chunk size gets hard-split.
            if (mb_strlen($sentence) > $chunkSize) {
                foreach ($this->hardSplit($sentence, $chunkSize, $overlap) as $piece) {
                    $chunks[] = trim($piece);
                }
                $current = '';
                continue;
            }
            $current = $overlap > 0 ? mb_substr($current, -$overlap) : '';
            $current .= $sentence;
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        $out = [];
        foreach (array_unique($chunks) as $chunk) {
            $out[] = ['content' => $chunk, 'tokens' => $this->estimateTokens($chunk)];
        }
        return $out;
    }

    private function splitSentences(string $text): array {
        // Keep punctuation with the sentence; normalise line breaks as separators.
        $text = preg_replace('/\n/', ' ', $text);
        $parts = preg_split('/(?<=[.!?:;])[ \t]+(?=\S)/u', $text) ?: [];
        $result = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $result[] = $p . ' ';
            }
        }
        return $result;
    }

    private function hardSplit(string $text, int $size, int $overlap): array {
        $pieces = [];
        $len = mb_strlen($text);
        $step = max(1, $size - $overlap);
        $start = 0;
        while ($start < $len) {
            $pieces[] = mb_substr($text, $start, $size);
            $start += $step;
        }
        return $pieces;
    }

    public function estimateTokens(string $text): int {
        return max(1, (int)ceil(mb_strlen($text) / 4));
    }
}