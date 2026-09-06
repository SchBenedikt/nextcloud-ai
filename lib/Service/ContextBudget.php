<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

/** Conservative UTF-8 byte estimate with a 25% output reserve. Provider tokenizers vary. */
class ContextBudget {
    public function prepare(array $messages, array $tools, int $context): array {
        $budget = max(128, (int)(max(256, min(131072, $context)) * 0.75));
        $cost = static fn(array $m): int => (int)ceil(strlen(json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) / 2) + 32;
        $toolCost = $tools === [] ? 0 : $cost($tools);
        $lastUser = null;
        foreach ($messages as $i => $m) { if (($m['role'] ?? '') === 'user') { $lastUser = $i; } }
        if ($lastUser === null) { throw new \InvalidArgumentException('A user prompt is required'); }
        $kept = []; $used = $toolCost; $reduced = false;
        // Security instructions and the current question are mandatory, never silently truncated.
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? '') === 'system' || $i === $lastUser) {
                $kept[$i] = $m; $used += $cost($m);
            }
        }
        if ($used > $budget) { throw new \InvalidArgumentException('The current question, system instructions and tool schemas exceed the model context. Shorten the question, disable tools, or increase context size.'); }
        // Preserve complete tool-call/result suffixes or reject; never send dangling tool messages.
        $suffix = array_slice($messages, $lastUser + 1, null, true);
        foreach ($suffix as $i => $m) {
            if (($m['role'] ?? '') === 'tool' && $cost($m) > 2048) {
                $m['content'] = mb_strcut((string)($m['content'] ?? ''), 0, 1800, 'UTF-8') . '\n[tool output reduced]'; $reduced = true;
            }
            $used += $cost($m); $kept[$i] = $m;
        }
        if ($used > $budget) { throw new \InvalidArgumentException('Tool results exceed the remaining model context. Increase context size.'); }
        for ($i = $lastUser - 1; $i >= 0; $i--) {
            if (isset($kept[$i])) { continue; }
            $m = $messages[$i];
            if (isset($m['tool_calls']) || ($m['role'] ?? '') === 'tool') { $reduced = true; continue; }
            if ($used + $cost($m) <= $budget) { $kept[$i] = $m; $used += $cost($m); }
            else { $reduced = true; }
        }
        ksort($kept);
        return ['messages' => array_values($kept), 'reduced' => $reduced, 'estimatedTokens' => $used, 'budget' => $budget];
    }
}
