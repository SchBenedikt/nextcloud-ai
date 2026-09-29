<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Builds bounded, unified previews for text changes proposed by EVA. */
class DiffService {
    private const MAX_LINES = 1500;
    private const MAX_CELLS = 250000;

    /**
     * @return array{diff:string,hunks:list<array{id:int,diff:string,old_start:int,old_count:int,new_lines:list<string>,added:int,removed:int,touches_eof:bool}>,added:int,removed:int,previewable:bool,ends_with_newline:bool,old_ends_with_newline:bool}
     */
    public function generateDiff(string $old, string $new): array {
        if (strlen($old) > 131072 || strlen($new) > 131072
            || str_contains($old, "\0") || str_contains($new, "\0")
            || !preg_match('//u', $old) || !preg_match('//u', $new)) {
            return ['diff' => '', 'hunks' => [], 'added' => 0, 'removed' => 0, 'previewable' => false, 'ends_with_newline' => false, 'old_ends_with_newline' => false];
        }

        $before = $old === '' ? [] : explode("\n", rtrim($old, "\n"));
        $after = $new === '' ? [] : explode("\n", rtrim($new, "\n"));
        $n = count($before);
        $m = count($after);
        if ($n > self::MAX_LINES || $m > self::MAX_LINES || (($n + 1) * ($m + 1)) > self::MAX_CELLS) {
            return ['diff' => '', 'hunks' => [], 'added' => 0, 'removed' => 0, 'previewable' => false, 'ends_with_newline' => str_ends_with($new, "\n"), 'old_ends_with_newline' => str_ends_with($old, "\n")];
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
        $ops = [];
        $added = 0;
        $removed = 0;
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $before[$i] === $after[$j]) {
                $ops[] = ['type' => ' ', 'line' => $before[$i++]];
                ++$j;
            } elseif ($j < $m && ($i === $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $ops[] = ['type' => '+', 'line' => $after[$j++]];
                ++$added;
            } else {
                $ops[] = ['type' => '-', 'line' => $before[$i++]];
                ++$removed;
            }
        }

        foreach ($ops as $op) {
            $lines[] = $op['type'] . $op['line'];
        }

        $hunks = [];
        $oldIndex = 0;
        $newIndex = 0;
        $hunkOldStart = null;
        $hunkNewStart = 0;
        $hunkOldCount = 0;
        $hunkAdded = 0;
        $hunkRemoved = 0;
        $hunkNewLines = [];
        $hunkDiffLines = [];
        $flushHunk = static function () use (&$hunks, &$hunkOldStart, &$hunkNewStart, &$hunkOldCount, &$hunkAdded, &$hunkRemoved, &$hunkNewLines, &$hunkDiffLines, $n, $m): void {
            if ($hunkOldStart === null) {
                return;
            }
            $id = count($hunks);
            $hunks[] = [
                'id' => $id,
                'diff' => '@@ -' . ($hunkOldStart + 1) . ',' . $hunkOldCount . ' +' . ($hunkNewStart + 1) . ',' . $hunkAdded . " @@\n" . implode("\n", $hunkDiffLines),
                'old_start' => $hunkOldStart,
                'old_count' => $hunkOldCount,
                'new_lines' => $hunkNewLines,
                'added' => $hunkAdded,
                'removed' => $hunkRemoved,
                'touches_eof' => $hunkOldStart + $hunkOldCount === $n || $hunkNewStart + $hunkAdded === $m,
            ];
            $hunkOldStart = null;
            $hunkOldCount = $hunkAdded = $hunkRemoved = 0;
            $hunkNewLines = [];
            $hunkDiffLines = [];
        };
        foreach ($ops as $op) {
            if ($op['type'] === ' ') {
                $flushHunk();
                ++$oldIndex;
                ++$newIndex;
                continue;
            }
            if ($hunkOldStart === null) {
                $hunkOldStart = $oldIndex;
                $hunkNewStart = $newIndex;
            }
            if ($op['type'] === '-') {
                ++$oldIndex;
                ++$hunkOldCount;
                ++$hunkRemoved;
                $hunkDiffLines[] = '-' . $op['line'];
            } else {
                ++$newIndex;
                ++$hunkAdded;
                $hunkNewLines[] = $op['line'];
                $hunkDiffLines[] = '+' . $op['line'];
            }
        }
        $flushHunk();

        return [
            'diff' => "--- current\n+++ proposed\n@@ -1,$n +1,$m @@\n" . implode("\n", $lines),
            'hunks' => $hunks,
            'added' => $added,
            'removed' => $removed,
            'previewable' => true,
            'ends_with_newline' => str_ends_with($new, "\n"),
            'old_ends_with_newline' => str_ends_with($old, "\n"),
        ];
    }

    /** Apply selected change blocks while retaining every rejected old block. */
    public function applySelectedHunks(string $old, array $hunks, array $selected, bool $endsWithNewline, bool $oldEndsWithNewline): string {
        $before = $old === '' ? [] : explode("\n", rtrim($old, "\n"));
        $availableIds = [];
        foreach ($hunks as $hunk) {
            if (!is_array($hunk) || !isset($hunk['id']) || !is_int($hunk['id']) || isset($availableIds[$hunk['id']])) {
                throw new \InvalidArgumentException('The diff blocks could not be verified; no changes were applied.');
            }
            $availableIds[$hunk['id']] = true;
        }
        $selectedIds = [];
        foreach ($selected as $id) {
            if (!is_int($id) || !isset($availableIds[$id]) || isset($selectedIds[$id])) {
                throw new \InvalidArgumentException('The selected diff blocks are invalid; no changes were applied.');
            }
            $selectedIds[$id] = true;
        }
        $result = [];
        $cursor = 0;
        $resultEndsWithNewline = $oldEndsWithNewline;
        foreach ($hunks as $hunk) {
            if (!is_array($hunk)) {
                continue;
            }
            $start = max($cursor, min(count($before), (int)($hunk['old_start'] ?? $cursor)));
            $count = max(0, min(count($before) - $start, (int)($hunk['old_count'] ?? 0)));
            array_push($result, ...array_slice($before, $cursor, $start - $cursor));
            if (isset($selectedIds[(int)($hunk['id'] ?? -1)])) {
                if (!empty($hunk['touches_eof'])) {
                    $resultEndsWithNewline = $endsWithNewline;
                }
                foreach (($hunk['new_lines'] ?? []) as $line) {
                    $result[] = (string)$line;
                }
            } else {
                array_push($result, ...array_slice($before, $start, $count));
            }
            $cursor = $start + $count;
        }
        array_push($result, ...array_slice($before, $cursor));
        $content = implode("\n", $result);
        return $resultEndsWithNewline && $result !== [] ? $content . "\n" : $content;
    }
}
