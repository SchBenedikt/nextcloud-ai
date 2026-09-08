<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

class Chunker {
    public function __construct(private AppConfig $config) {
    }

    /**
     * Split raw text into overlapping sentence-boundary chunks.
     *
     * Markdown headings (ATX style, `#`-`######`) become structural anchors:
     * a heading starts a new chunk and every chunk of its section carries the
     * heading as a prefix, so retrieval keeps the section context and does not
     * turn "## Budget 2026" + body into an undifferentiated text wall
     * (Issue #147). Documents without headings chunk exactly as before.
     *
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

        $chunks = [];
        foreach ($this->sectionsWithHeadings($text) as $section) {
            foreach ($this->chunkSection($section['heading'], $section['body'], $chunkSize, $overlap) as $c) {
                $chunks[] = $c;
            }
        }
        return $chunks;
    }

    /**
     * Split the text at markdown heading lines. Each section keeps the heading
     * of the section it belongs to (a heading alone marks the start of the
     * next section, it is never part of the body).
     *
     * @return list<array{heading:string,body:string}>
     */
    private function sectionsWithHeadings(string $text): array {
        $lines = preg_split('/\n/', $text) ?: [];
        $sections = [];
        $currentHeading = '';
        $currentBody = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^#{1,6}\s+\S/u', $trimmed)) {
                // Start a new section: flush the previous one.
                $sections[] = ['heading' => $currentHeading, 'body' => $currentBody];
                $currentHeading = $trimmed;
                $currentBody = '';
                continue;
            }
            $currentBody .= ($currentBody === '' ? '' : "\n") . $line;
        }
        $sections[] = ['heading' => $currentHeading, 'body' => $currentBody];
        return $sections;
    }

    /**
     * Chunk one section. The heading (if any) is prepended to every chunk of
     * the section; the body budget is reduced by the heading length so a
     * chunk never exceeds the configured chunk size.
     *
     * @return list<array{content:string,tokens:int}>
     */
    private function chunkSection(string $heading, string $body, int $chunkSize, int $overlap): array {
        if (trim($body) === '') {
            return $heading !== '' ? [['content' => $heading, 'tokens' => $this->estimateTokens($heading)]] : [];
        }
        $prefix = $heading !== '' ? trim($heading) . "\n\n" : '';
        // Only shrink the body budget when a heading actually consumes space;
        // heading-free text must keep the exact configured chunk size.
        $budget = $prefix === '' ? $chunkSize : max(1, $chunkSize - mb_strlen($prefix));
        $out = [];
        foreach ($this->chunkPlain($body, $budget, $overlap) as $c) {
            $content = trim($prefix . $c['content']);
            $out[] = ['content' => $content, 'tokens' => $this->estimateTokens($content)];
        }
        return $out;
    }

    /**
     * The plain sentence-boundary chunker (no heading context).
     *
     * @return list<array{content:string,tokens:int}>
     */
    private function chunkPlain(string $text, int $chunkSize, int $overlap): array {
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
                $chunks[] = ['content' => trim($current), 'tokens' => $this->estimateTokens(trim($current))];
            }
            // A single sentence longer than the chunk size gets hard-split.
            if (mb_strlen($sentence) > $chunkSize) {
                foreach ($this->hardSplit($sentence, $chunkSize, $overlap) as $piece) {
                    $chunks[] = ['content' => trim($piece), 'tokens' => $this->estimateTokens(trim($piece))];
                }
                $current = '';
                continue;
            }
            $current = $overlap > 0 ? mb_substr($current, -$overlap) : '';
            $current .= $sentence;
        }
        if (trim($current) !== '') {
            $chunks[] = ['content' => trim($current), 'tokens' => $this->estimateTokens(trim($current))];
        }
        return $chunks;
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