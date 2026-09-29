<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Adapts the read-only activity service to the tool registry. */
final class ActivityToolExecutor implements DomainToolExecutor {
    public function __construct(private ActivityService $activity) {
    }

    public function tools(): array {
        return ['recent_activity'];
    }

    public function execute(string $tool, string $userId, array $args): array {
        if ($tool !== 'recent_activity') return ['ok' => false, 'error' => 'Unsupported activity tool: ' . $tool];
        return $this->activity->recent($userId, $args);
    }
}
