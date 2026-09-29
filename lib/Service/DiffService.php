<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Builds bounded, unified previews for text changes proposed by EVA. */
class DiffService {
    private const MAX_LINES = 1500;
    private const MAX_CELLS = 250000;

    /**
     * @return array{diff:string,added:int,removed:int,previewable:bool}
     */
    public function generateDiff(string $old, string $new): array {
        if (strlen($old) > 131072 || strlen($new) > 131072
            || str_contains($old, "\0") || str_contains($new, "\0")
            || !preg_match('//u', $old) || !preg_match('//u', $new)) {
            return ['diff' => '', 'added' => 0, 'removed' => 0, 'previewable' => false];
        }

        $before = $old === '' ? [] : explode("\n", rtrim($old, "\n"));
        $after = $new === '' ? [] : explode("\n", rtrim($new, "\n"));
        $n = count($before);
        $m = count($after);
        if ($n > self::MAX_LINES || $m > self::MAX_LINES || (($n + 1) * ($m + 1)) > self::MAX_CELLS) {
            return ['diff' => '', 'added' => 0, 'removed' => 0, 'previewable' => false];
        }

        // Longest common subsequence gives a compact, deterministic unified diff.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lcs[$i][$j] = $before[$i] === $after[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $lines = [];
        $added = 0;
        $removed = 0;
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $before[$i] === $after[$j]) {
                $lines[] = ' ' . $before[$i++];
                ++$j;
            } elseif ($j < $m && ($i === $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $lines[] = '+' . $after[$j++];
                ++$added;
            } else {
                $lines[] = '-' . $before[$i++];
                ++$removed;
            }
        }

        return [
            'diff' => "--- current\n+++ proposed\n@@ -1,$n +1,$m @@\n" . implode("\n", $lines),
            'added' => $added,
            'removed' => $removed,
            'previewable' => true,
        ];
    }
}
