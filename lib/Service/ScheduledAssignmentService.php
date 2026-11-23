<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for interacting with Nextcloud Assistant's "Geplante Aufgaben" (Scheduled Assignments).
 * Uses the authenticated OCS API exposed by the official Assistant app.
 */
class ScheduledAssignmentService {
    private \OCP\Http\Client\IClient $client;

    public function __construct(
        IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private IRequest $request,
        private IUserSession $userSession,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        $this->client = $clientService->newClient();
    }

    /**
     * List all scheduled assignments for the user.
     *
     * @param string $userId
     * @return list<array{id:int,user_id:string,prompt:string,recurrence:string,starts_at:int,created_at:int,updated_at:int,last_run_at:int,timezone:string}>
     */
    public function listAssignments(string $userId): array {
        $data = $this->requestAssistant('get', $userId, 'list');
        $assignments = $data['assignments'] ?? null;
        if (!is_array($assignments) || !array_is_list($assignments)) {
            throw new \UnexpectedValueException('Nextcloud Assistant returned an invalid assignments response.');
        }
        return $assignments;
    }

    /**
     * Create a new scheduled assignment.
     *
     * @param string $userId
     * @param string $title The title shown for the scheduled task
     * @param string $prompt The prompt to execute on schedule
     * @param string $recurrence RFC 5545 RRULE string (e.g. "FREQ=DAILY;INTERVAL=1")
     * @param int $startsAt Unix timestamp when to start
     * @param string $timezone Timezone name (e.g. "Europe/Berlin")
     * @return array{id:int,user_id:string,prompt:string,recurrence:string,starts_at:int,created_at:int,updated_at:int,last_run_at:int,timezone:string}
     */
    public function createAssignment(string $userId, string $title, string $prompt, string $recurrence, int $startsAt, string $timezone): array {
        $data = $this->requestAssistant('post', $userId, 'create', payload: [
            'title' => $title,
            'prompt' => $prompt,
            'recurrence' => $recurrence,
            'startsAt' => $startsAt,
            'timezone' => $timezone,
        ]);
        $assignment = $data['assignment'] ?? null;
        if (!is_array($assignment)) {
            throw new \UnexpectedValueException('Nextcloud Assistant returned an invalid assignment response.');
        }
        return $assignment;
    }

    /** Return the user's Nextcloud timezone, falling back to the server default. */
    public function userTimeZone(string $userId): \DateTimeZone {
        $timezone = trim($this->config->getUserValue($userId, 'core', 'timezone', ''));
        if ($timezone === '') {
            $timezone = (string)$this->config->getSystemValue('default_timezone', 'UTC');
        }
        try {
            return new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Delete a scheduled assignment.
     *
     * @param string $userId
     * @param int $assignmentId
     * @return bool True when the Assistant confirms the deletion
     */
    public function deleteAssignment(string $userId, int $assignmentId): bool {
        if ($assignmentId <= 0) {
            throw new \InvalidArgumentException('Assignment id must be a positive integer.');
        }
        $this->requestAssistant('delete', $userId, 'delete', $assignmentId);
        return true;
    }

    /**
     * Update a scheduled assignment.
     *
     * @param string $userId
     * @param int $assignmentId
     * @param array{prompt?:string,recurrence?:string,startsAt?:int,timezone?:string} $updates
     * @return array{id:int,user_id:string,prompt:string,recurrence:string,starts_at:int,created_at:int,updated_at:int,last_run_at:int,timezone:string}
     */
    public function updateAssignment(string $userId, int $assignmentId, array $updates): array {
        if ($assignmentId <= 0) {
            throw new \InvalidArgumentException('Assignment id must be a positive integer.');
        }
        $allowedFields = ['prompt', 'recurrence', 'startsAt', 'timezone'];
        $updates = array_intersect_key($updates, array_flip($allowedFields));
        if ($updates === []) {
            throw new \InvalidArgumentException('At least one supported assignment field must be supplied.');
        }
        $data = $this->requestAssistant('patch', $userId, 'update', $assignmentId, $updates);
        $assignment = $data['assignment'] ?? null;
        if (!is_array($assignment)) {
            throw new \UnexpectedValueException('Nextcloud Assistant returned an invalid assignment response.');
        }
        return $assignment;
    }

    /**
     * Send one authenticated request to the fixed, same-origin Assistant API.
     * Credentials are taken from the active Nextcloud request and are never
     * attached to a configurable or redirected URL.
     *
     * @return array<string, mixed> OCS data payload
     */
    private function requestAssistant(string $method, string $userId, string $operation, ?int $assignmentId = null, ?array $payload = null): array {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null || $currentUser->getUID() !== $userId) {
            throw new \RuntimeException('Scheduled assignments require the matching authenticated Nextcloud user.');
        }

        $url = $this->assignmentsUrl();
        if ($assignmentId !== null) {
            $url .= '/' . $assignmentId;
        }
        $headers = [
            'OCS-APIREQUEST' => 'true',
            'Accept' => 'application/json',
        ];
        foreach (['Authorization', 'Cookie', 'requesttoken'] as $header) {
            $value = trim($this->request->getHeader($header));
            if ($value !== '') {
                $headers[$header] = $value;
            }
        }
        if (!isset($headers['Authorization']) && !isset($headers['Cookie'])) {
            throw new \RuntimeException('The active Nextcloud session credentials are not available for the Assistant API request.');
        }

        $options = [
            'headers' => $headers,
            'allow_redirects' => false,
        ];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $options['headers'] = $headers;
            $options['body'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $statusCode = 0;
        try {
            $response = $this->client->{$method}($url, $options);
            $statusCode = $response->getStatusCode();
            $body = trim($response->getBody());
            $data = null;
            $metaStatus = $statusCode;
            if ($body !== '') {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $ocs = $decoded['ocs'] ?? null;
                $metaStatus = is_array($ocs) ? (int)($ocs['meta']['statuscode'] ?? $statusCode) : $statusCode;
                $data = is_array($ocs) ? ($ocs['data'] ?? null) : null;
            }
            if ($statusCode < 200 || $statusCode >= 300 || $metaStatus < 200 || $metaStatus >= 300
                || ($method !== 'delete' && !is_array($data))
                || ($body !== '' && !is_array($data) && $method === 'delete')) {
                throw new \RuntimeException('The Nextcloud Assistant API returned an unsuccessful response.');
            }
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai scheduled assignment API request failed', [
                'operation' => $operation,
                'user' => $userId,
                'status_code' => $statusCode,
                'exception' => get_class($e),
            ]);
            throw new \RuntimeException('Nextcloud Assistant ' . $operation . ' request failed.', 0, $e);
        }
    }

    private function assignmentsUrl(): string {
        try {
            $url = $this->urlGenerator->linkToOCSRouteAbsolute('assistant.AssignmentsApi.getUserAssignments');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Nextcloud Assistant scheduled assignments are unavailable.', 0, $e);
        }

        $target = parse_url($url);
        $instance = parse_url($this->urlGenerator->getAbsoluteURL('/'));
        $targetPath = is_array($target) ? rtrim((string)($target['path'] ?? ''), '/') : '';
        if (!is_array($target) || !is_array($instance)
            || strtolower((string)($target['scheme'] ?? '')) !== strtolower((string)($instance['scheme'] ?? ''))
            || strtolower((string)($target['host'] ?? '')) !== strtolower((string)($instance['host'] ?? ''))
            || (int)($target['port'] ?? 0) !== (int)($instance['port'] ?? 0)
            || !str_ends_with($targetPath, '/apps/assistant/api/v1/assignments')
            || isset($target['user']) || isset($target['pass']) || isset($target['query']) || isset($target['fragment'])) {
            throw new \RuntimeException('Refusing to send Nextcloud session credentials outside this instance.');
        }

        return rtrim($url, '/');
    }

    /**
     * Convert a human-readable recurrence description to RFC 5545 RRULE.
     *
     * @param string $recurrenceDescription e.g. "täglich", "wöchentlich", "alle 2 Tage", "jeden Montag"
     * @return string RFC 5545 RRULE
     */
    public function parseRecurrence(string $recurrenceDescription): string {
        $desc = strtolower(trim($recurrenceDescription));

        // Simple mappings
        $simpleMap = [
            'minütlich' => 'FREQ=MINUTELY',
            'stündlich' => 'FREQ=HOURLY',
            'täglich' => 'FREQ=DAILY',
            'jeden tag' => 'FREQ=DAILY',
            'jeden_tag' => 'FREQ=DAILY',
            'wöchentlich' => 'FREQ=WEEKLY',
            'jede woche' => 'FREQ=WEEKLY',
            'monatlich' => 'FREQ=MONTHLY',
            'jeden monat' => 'FREQ=MONTHLY',
            'jährlich' => 'FREQ=YEARLY',
            'jedes jahr' => 'FREQ=YEARLY',
        ];

        if (isset($simpleMap[$desc])) {
            return $simpleMap[$desc];
        }

        // "alle N Tage/Wochen/Monate"
        if (preg_match('/^alle\s+(\d+)\s+(tag|tage|woche|wochen|monat|monate|jahr|jahre)$/i', $desc, $m)) {
            $interval = (int)$m[1];
            $unit = strtolower($m[2]);
            $freqMap = [
                'tag' => 'DAILY', 'tage' => 'DAILY',
                'woche' => 'WEEKLY', 'wochen' => 'WEEKLY',
                'monat' => 'MONTHLY', 'monate' => 'MONTHLY',
                'jahr' => 'YEARLY', 'jahre' => 'YEARLY',
            ];
            return 'FREQ=' . $freqMap[$unit] . ';INTERVAL=' . $interval;
        }

        // "jeden Montag", "jeden Dienstag", etc.
        $dayMap = [
            'montag' => 'MO', 'dienstag' => 'TU', 'mittwoch' => 'WE',
            'donnerstag' => 'TH', 'freitag' => 'FR', 'samstag' => 'SA', 'sonntag' => 'SU',
        ];
        if (preg_match('/^jeden\s+(montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag)$/i', $desc, $m)) {
            return 'FREQ=WEEKLY;BYDAY=' . $dayMap[strtolower($m[1])];
        }

        // Default: try to interpret as daily
        return 'FREQ=DAILY';
    }
}
