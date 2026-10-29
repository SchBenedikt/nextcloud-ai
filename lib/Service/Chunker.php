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
        $chunkSize = $this->config->getInt('chunk_size', 900);
        $overlap = $this->config->getInt('chunk_overlap', 120);
        if ($overlap >= $chunkSize) {
            $overlap = max(0, (int)($chunkSize / 4));
        }

        return iterator_to_array($this->chunks($text, $chunkSize, $overlap), false);
    }

    /** Explicit immutable generation settings; bounded before materializing chunks. */
    public function chunkWithSettings(string $text, int $chunkSize, int $overlap, int $maxChunks = 4096, int $maxBytes = 16777216): array {
        if ($chunkSize < 1 || $chunkSize > 10000 || $overlap < 0 || $overlap >= $chunkSize
            || $maxChunks < 1 || $maxChunks > 10000 || $maxBytes<1 || $maxBytes>16777216) throw new \InvalidArgumentException('Invalid bounded chunk settings.');
        $chunks = []; $bytes=0;
        foreach ($this->chunks($text, $chunkSize, $overlap) as $chunk) {
            $bytes+=strlen($chunk['content']);
            if (count($chunks) === $maxChunks || $bytes>$maxBytes) throw new \LengthException('Source exceeds the generation chunk budget.');
            $chunks[] = $chunk;
        }
        return $chunks;
    }

    private function chunks(string $text, int $chunkSize, int $overlap): \Generator {
        if (trim($text) === '') return;
        // Normalise whitespace runs but keep line structure.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        foreach ($this->sectionsWithHeadings($text) as $section) {
            foreach ($this->chunkSection($section['heading'], $section['body'], $chunkSize, $overlap) as $c) {
                $c['provenance'] = ['version' => 1, 'section' => mb_strcut($section['heading'], 0, 200, 'UTF-8'), 'section_offset' => $section['offset'],
                    'heading_path' => array_map(static fn(string $heading):string=>mb_strcut($heading,0,64,'UTF-8'),$section['headings'])];
                if (preg_match('/^\[Page (\d+)\]$/', $section['heading'], $match)) $c['provenance']['page'] = (int)$match[1];
                if (preg_match('/^\[Slide (\d+)\]$/', $section['heading'], $match)) $c['provenance']['slide'] = (int)$match[1];
                if (preg_match('/^\[Sheet: (.+)\]$/u', $section['heading'], $match)) $c['provenance']['sheet'] = mb_strcut($match[1], 0, 100, 'UTF-8');
                yield $c;
            }
        }
    }

    /**
     * Split the text at markdown heading lines. Each section keeps the heading
     * of the section it belongs to (a heading alone marks the start of the
     * next section, it is never part of the body).
     *
     * @return \Generator<int,array{heading:string,body:string,offset:int,headings:array}>
     */
    private function sectionsWithHeadings(string $text): \Generator {
        $currentHeading = '';
        $currentBody = '';
        $offset = 0;
        $sectionOffset = 0;
        $headings = [];
        $length = strlen($text);
        for ($start = 0; $start <= $length; $start = $end + 1) {
            $end = strpos($text, "\n", $start);
            if ($end === false) $end = $length;
            $line = substr($text, $start, $end - $start);
            $trimmed = trim($line);
            if (preg_match('/^#{1,6}\s+\S/u', $trimmed) || preg_match('/^\[[^\]\r\n]{1,100}\]$/u', $trimmed)) {
                // Start a new section: flush the previous one.
                yield ['heading' => $currentHeading, 'body' => $currentBody, 'offset' => $sectionOffset, 'headings' => array_values($headings)];
                $currentHeading = $trimmed;
                $sectionOffset = $offset;
                if (preg_match('/^(#{1,6})\s+(.*)$/u', $trimmed, $match)) {
                    $level = strlen($match[1]);
                    $headings = array_filter($headings, static fn($key) => $key < $level, ARRAY_FILTER_USE_KEY);
                    $headings[$level] = mb_substr($match[2], 0, 100);
                }
                $offset += mb_strlen($line) + 1;
                $currentBody = '';
                continue;
            }
            $currentBody .= ($currentBody === '' ? '' : "\n") . $line;
            $offset += mb_strlen($line) + 1;
        }
        yield ['heading' => $currentHeading, 'body' => $currentBody, 'offset' => $sectionOffset, 'headings' => array_values($headings)];
    }

    /**
     * Chunk one section. The heading (if any) is prepended to every chunk of
     * the section; the body budget is reduced by the heading length so a
     * chunk never exceeds the configured chunk size.
     *
     * @return \Generator<int,array{content:string,tokens:int}>
     */
    private function chunkSection(string $heading, string $body, int $chunkSize, int $overlap): \Generator {
        if (trim($body) === '') {
            if ($heading !== '') yield from $this->chunkPlain($heading, $chunkSize, $overlap);
            return;
        }
        $prefix = $heading !== '' ? trim($heading) . "\n\n" : '';
        if (mb_strlen($prefix) >= $chunkSize) {
            // Keep the complete heading as text instead of exceeding the model
            // budget or silently dropping its suffix. Provenance keeps context.
            yield from $this->chunkPlain($prefix . $body, $chunkSize, $overlap);
            return;
        }
        // Only shrink the body budget when a heading actually consumes space;
        // heading-free text must keep the exact configured chunk size.
        $budget = $prefix === '' ? $chunkSize : max(1, $chunkSize - mb_strlen($prefix));
        foreach ($this->chunkPlain($body, $budget, $overlap) as $c) {
            $content = trim($prefix . $c['content']);
            yield ['content' => $content, 'tokens' => $this->estimateTokens($content)];
        }
    }

    /**
     * The plain sentence-boundary chunker (no heading context).
     *
     * @return \Generator<int,array{content:string,tokens:int}>
     */
    private function chunkPlain(string $text, int $chunkSize, int $overlap): \Generator {
        $sentences = $this->splitSentences($text);

        $current = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($current . $sentence) <= $chunkSize) {
                $current .= $sentence;
                continue;
            }
            if ($current !== '') {
                yield ['content' => trim($current), 'tokens' => $this->estimateTokens(trim($current))];
            }
            // A single sentence longer than the chunk size gets hard-split.
            if (mb_strlen($sentence) > $chunkSize) {
                foreach ($this->hardSplit($sentence, $chunkSize, $overlap) as $piece) {
                    $piece = trim($piece);
                    if ($piece !== '') yield ['content' => $piece, 'tokens' => $this->estimateTokens($piece)];
                }
                $current = '';
                continue;
            }
            $retained = min($overlap, max(0, $chunkSize - mb_strlen($sentence)));
            $current = $retained > 0 ? mb_substr($current, -$retained) : '';
            $current .= $sentence;
        }
        if (trim($current) !== '') {
            yield ['content' => trim($current), 'tokens' => $this->estimateTokens(trim($current))];
        }
    }

    private function splitSentences(string $text): \Generator {
        // Keep punctuation with the sentence; normalise line breaks as separators.
        // Preserve line boundaries for rows and paragraphs.
        $length = strlen($text);
        for ($start = 0; $start < $length; $start = $end) {
            $found = preg_match('/(?<=[.!?:;])[ \t]+(?=\S)|\n/u', $text, $match, PREG_OFFSET_CAPTURE, $start);
            if ($found === false) throw new \InvalidArgumentException('Chunk text must be valid UTF-8.');
            $position = $found === 1 ? $match[0][1] : $length;
            $newline = $found === 1 && $match[0][0] === "\n";
            $part = trim(substr($text, $start, $position - $start));
            $end = $found === 1 ? $position + strlen($match[0][0]) : $length;
            if ($part !== '') yield $part . ($newline ? "\n" : ' ');
        }
    }

    private function hardSplit(string $text, int $size, int $overlap): \Generator {
        $len = mb_strlen($text);
        $step = max(1, $size - $overlap);
        $start = 0;
        while ($start < $len) {
            yield mb_substr($text, $start, $size);
            $start += $step;
        }
    }

    public function estimateTokens(string $text): int {
        return max(1, (int)ceil(mb_strlen($text) / 4));
    }
}
