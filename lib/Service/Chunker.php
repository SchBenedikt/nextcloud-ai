<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

class Chunker {
    public function __construct(private AppConfig $config) {}

    /** @return list<array{content:string,tokens:int,provenance:array}> */
    public function chunk(string $text): array { return iterator_to_array($this->iterate($text), false); }

    /** Preserve source order, repeated passages, line boundaries and compact provenance. */
    public function iterate(string $text): \Generator {
        $size = max(128, min(10000, $this->config->getInt('chunk_size', 900)));
        $overlap = max(0, min($size - 1, $this->config->getInt('chunk_overlap', 120)));
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $length = mb_strlen($text); $offset = 0; $heading = '';
        while ($offset < $length) {
            $piece = mb_substr($text, $offset, $size);
            if ($offset + $size < $length) {
                // Prefer paragraph/row boundaries over arbitrary character cuts.
                $boundary = mb_strrpos($piece, "\n");
                if ($boundary !== false && $boundary >= (int)($size / 2)) { $piece = mb_substr($piece, 0, $boundary + 1); }
            }
            if (preg_match_all('/^#{1,6}\s+([^\n]+)/mu', $piece, $matches)) { $heading = mb_substr((string)end($matches[1]), 0, 240); }
            $consumed = mb_strlen($piece);
            if (trim($piece) !== '') {
                yield ['content' => trim($piece), 'tokens' => $this->estimateTokens($piece),
                    'provenance' => ['version' => 1, 'heading' => $heading, 'offset' => $offset, 'length' => $consumed]];
            }
            if ($offset + $consumed >= $length) { break; }
            $offset += max(1, $consumed - min($overlap, (int)($consumed / 2)));
        }
    }
    public function estimateTokens(string $text): int { return max(1, (int)ceil(mb_strlen($text) / 4)); }
}
