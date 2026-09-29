<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Owns EVA's per-user scheduled briefing and assignment tools.
 */
final class BriefingToolExecutor implements DomainToolExecutor {
    private const TOOLS = [
        'list_scheduled_briefings', 'create_scheduled_briefing', 'update_scheduled_briefing', 'delete_scheduled_briefing',
        'list_scheduled_assignments', 'create_scheduled_assignment', 'update_scheduled_assignment', 'delete_scheduled_assignment',
    ];

    public function __construct(
        private AppConfig $config,
        private ?ScheduledAssignmentService $scheduledAssignments = null,
    ) {
    }

    public function tools(): array {
        return self::TOOLS;
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'list_scheduled_briefings' => $this->listScheduledBriefings(),
            'create_scheduled_briefing' => $this->createScheduledBriefing($args),
            'update_scheduled_briefing' => $this->updateScheduledBriefing($args),
            'delete_scheduled_briefing' => $this->deleteScheduledBriefing($args),
            'list_scheduled_assignments' => $this->listScheduledAssignments(),
            'create_scheduled_assignment' => $this->createScheduledAssignment($args),
            'update_scheduled_assignment' => $this->updateScheduledAssignment($args),
            'delete_scheduled_assignment' => $this->deleteScheduledAssignment($args),
            default => ['ok' => false, 'error' => 'Unsupported briefing tool: ' . $tool],
        };
    }

    private function briefingRows(): array {
        $rows = json_decode($this->config->get('proactive_schedules'), true);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function validBriefingFields(string $prompt, string $time, array $days): ?string {
        if ($prompt === '' || mb_strlen($prompt) > 2000) return 'prompt must contain 1-2000 characters.';
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) return 'time must use HH:MM format.';
        $days = array_values(array_unique(array_map('intval', $days)));
        if ($days === [] || count($days) > 7 || array_diff($days, [1, 2, 3, 4, 5, 6, 7]) !== []) return 'days must contain weekdays 1-7.';
        return null;
    }

    private function listScheduledBriefings(): array {
        $rows = $this->briefingRows();
        return ['ok' => true, 'result' => ['briefings' => array_map(static function (array $row): array {
            return ['id' => (string)($row['id'] ?? ''), 'prompt' => (string)($row['prompt'] ?? ''), 'time' => (string)($row['time'] ?? ''), 'days' => array_values(array_map('intval', is_array($row['days'] ?? null) ? $row['days'] : [])), 'enabled' => ($row['enabled'] ?? true) === true, 'allow_actions' => ($row['allow_actions'] ?? false) === true];
        }, $rows)]];
    }

    private function persistBriefings(array $rows): void {
        $this->config->set('proactive_schedules', json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function createScheduledBriefing(array $args): array {
        $prompt = trim((string)($args['prompt'] ?? ''));
        $time = trim((string)($args['time'] ?? ''));
        $days = is_array($args['days'] ?? null) ? array_values(array_unique(array_map('intval', $args['days']))) : [];
        $error = $this->validBriefingFields($prompt, $time, $days);
        if ($error !== null) return ['ok' => false, 'error' => $error];
        $rows = $this->briefingRows();
        if (count($rows) >= 20) return ['ok' => false, 'error' => 'At most 20 scheduled briefings are allowed.'];
        $id = 'briefing-' . bin2hex(random_bytes(5));
        $row = ['id' => $id, 'prompt' => $prompt, 'time' => $time, 'days' => $days, 'enabled' => true, 'allow_actions' => ($args['allow_actions'] ?? false) === true];
        $rows[] = $row; $this->persistBriefings($rows);
        return ['ok' => true, 'result' => ['briefing' => $row]];
    }

    private function updateScheduledBriefing(array $args): array {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($args['briefing_id'] ?? '')) ?? '';
        if ($id === '') return ['ok' => false, 'error' => 'briefing_id is required.'];
        $rows = $this->briefingRows(); $found = false; $updated = null;
        foreach ($rows as &$row) {
            if ((string)($row['id'] ?? '') !== $id) continue;
            $prompt = array_key_exists('prompt', $args) ? trim((string)$args['prompt']) : (string)($row['prompt'] ?? '');
            $time = array_key_exists('time', $args) ? trim((string)$args['time']) : (string)($row['time'] ?? '');
            $days = array_key_exists('days', $args) && is_array($args['days']) ? array_values(array_unique(array_map('intval', $args['days']))) : (array)$row['days'];
            $error = $this->validBriefingFields($prompt, $time, $days);
            if ($error !== null) return ['ok' => false, 'error' => $error];
            $row['prompt'] = $prompt; $row['time'] = $time; $row['days'] = $days;
            if (array_key_exists('enabled', $args)) $row['enabled'] = $args['enabled'] === true;
            if (array_key_exists('allow_actions', $args)) $row['allow_actions'] = $args['allow_actions'] === true;
            $updated = $row; $found = true; break;
        }
        unset($row);
        if (!$found) return ['ok' => false, 'error' => 'Scheduled briefing not found.'];
        $this->persistBriefings($rows);
        return ['ok' => true, 'result' => ['briefing' => $updated]];
    }

    private function deleteScheduledBriefing(array $args): array {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($args['briefing_id'] ?? '')) ?? '';
        if ($id === '') return ['ok' => false, 'error' => 'briefing_id is required.'];
        $rows = $this->briefingRows(); $filtered = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['id'] ?? '') !== $id));
        if (count($filtered) === count($rows)) return ['ok' => false, 'error' => 'Scheduled briefing not found.'];
        $this->persistBriefings($filtered);
        return ['ok' => true, 'result' => ['briefing_id' => $id, 'deleted' => true]];
    }

    private function listScheduledAssignments(): array {
        if ($this->scheduledAssignments === null) {
            return ['ok' => false, 'error' => 'Scheduled assignments service is not available.'];
        }
        $userId = $this->config->userId() ?? '';
        if ($userId === '') return ['ok' => false, 'error' => 'No user logged in.'];
        try {
            $assignments = $this->scheduledAssignments->listAssignments($userId);
            return ['ok' => true, 'result' => ['assignments' => $assignments]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Failed to list scheduled assignments: ' . $e->getMessage()];
        }
    }

    private function createScheduledAssignment(array $args): array {
        if ($this->scheduledAssignments === null) {
            return ['ok' => false, 'error' => 'Scheduled assignments service is not available.'];
        }
        $userId = $this->config->userId() ?? '';
        if ($userId === '') return ['ok' => false, 'error' => 'No user logged in.'];
        $prompt = trim((string)($args['prompt'] ?? ''));
        if ($prompt === '') return ['ok' => false, 'error' => 'prompt is required.'];
        $recurrence = trim((string)($args['recurrence'] ?? ''));
        if ($recurrence === '') return ['ok' => false, 'error' => 'recurrence is required (e.g. "täglich", "wöchentlich", "alle 2 Tage").'];
        // Parse human-readable recurrence to RFC 5545
        $rrule = $this->scheduledAssignments->parseRecurrence($recurrence);
        // Parse start time
        $startsAt = time(); // Default: now
        $startsAtStr = trim((string)($args['starts_at'] ?? ''));
        if ($startsAtStr !== '') {
            try {
                $dt = new \DateTime($startsAtStr, new \DateTimeZone($args['timezone'] ?? 'Europe/Berlin'));
                $startsAt = $dt->getTimestamp();
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Invalid starts_at format: ' . $e->getMessage()];
            }
        }
        $timezone = trim((string)($args['timezone'] ?? '')) ?: 'Europe/Berlin';
        try {
            $result = $this->scheduledAssignments->createAssignment($userId, $prompt, $rrule, $startsAt, $timezone);
            if ($result === null) {
                return ['ok' => false, 'error' => 'Failed to create scheduled assignment.'];
            }
            return ['ok' => true, 'result' => ['assignment' => $result]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Failed to create scheduled assignment: ' . $e->getMessage()];
        }
    }

    private function updateScheduledAssignment(array $args): array {
        if ($this->scheduledAssignments === null) {
            return ['ok' => false, 'error' => 'Scheduled assignments service is not available.'];
        }
        $userId = $this->config->userId() ?? '';
        if ($userId === '') return ['ok' => false, 'error' => 'No user logged in.'];
        $assignmentId = (int)($args['assignment_id'] ?? 0);
        if ($assignmentId <= 0) return ['ok' => false, 'error' => 'assignment_id is required.'];
        $updates = [];
        if (array_key_exists('prompt', $args)) $updates['prompt'] = trim((string)$args['prompt']);
        if (array_key_exists('recurrence', $args)) {
            $recurrence = trim((string)$args['recurrence']);
            $updates['recurrence'] = $this->scheduledAssignments->parseRecurrence($recurrence);
        }
        if (array_key_exists('starts_at', $args)) {
            $startsAtStr = trim((string)$args['starts_at']);
            if ($startsAtStr !== '') {
                try {
                    $dt = new \DateTime($startsAtStr, new \DateTimeZone($args['timezone'] ?? 'Europe/Berlin'));
                    $updates['startsAt'] = $dt->getTimestamp();
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => 'Invalid starts_at format: ' . $e->getMessage()];
                }
            }
        }
        if (array_key_exists('timezone', $args)) $updates['timezone'] = trim((string)$args['timezone']);
        if (empty($updates)) return ['ok' => false, 'error' => 'No fields to update.'];
        try {
            $result = $this->scheduledAssignments->updateAssignment($userId, $assignmentId, $updates);
            if ($result === null) {
                return ['ok' => false, 'error' => 'Failed to update scheduled assignment.'];
            }
            return ['ok' => true, 'result' => ['assignment' => $result]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Failed to update scheduled assignment: ' . $e->getMessage()];
        }
    }

    private function deleteScheduledAssignment(array $args): array {
        if ($this->scheduledAssignments === null) {
            return ['ok' => false, 'error' => 'Scheduled assignments service is not available.'];
        }
        $userId = $this->config->userId() ?? '';
        if ($userId === '') return ['ok' => false, 'error' => 'No user logged in.'];
        $assignmentId = (int)($args['assignment_id'] ?? 0);
        if ($assignmentId <= 0) return ['ok' => false, 'error' => 'assignment_id is required.'];
        try {
            $deleted = $this->scheduledAssignments->deleteAssignment($userId, $assignmentId);
            if (!$deleted) {
                return ['ok' => false, 'error' => 'Failed to delete scheduled assignment.'];
            }
            return ['ok' => true, 'result' => ['assignment_id' => (string)$assignmentId, 'deleted' => true]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Failed to delete scheduled assignment: ' . $e->getMessage()];
        }
    }

}
