<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for interacting with Nextcloud Assistant's "Geplante Aufgaben" (Scheduled Assignments).
 * Uses the OCS API of the assistant app.
 */
class ScheduledAssignmentService {
    private \OCP\Http\Client\IClient $client;
    private string $baseUrl;

    public function __construct(
        IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        $this->client = $clientService->newClient();
        $this->baseUrl = rtrim($this->urlGenerator->linkToRouteAbsolute('ocs.AssignmentApi.list'), '/');
        // Ensure we have the base URL without the route
        if (str_ends_with($this->baseUrl, '/ocs/v1/appassistant/assignments')) {
            $this->baseUrl = substr($this->baseUrl, 0, -strlen('/ocs/v1/appassistant/assignments'));
        }
    }

    /**
     * List all scheduled assignments for the user.
     *
     * @param string $userId
     * @return array<int,array{id:int,prompt:string,recurrence:string,startsAt:int,timezone:string,createdAt:int}>
     */
    public function listAssignments(string $userId): array {
        $url = $this->baseUrl . '/ocs/v1/appassistant/assignments';
        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'OCS-APIREQUEST' => 'true',
                    'Accept' => 'application/json',
                ],
            ]);
            $body = json_decode($response->getBody(), true);
            if (!isset($body['ocs']['data']) || !is_array($body['ocs']['data'])) {
                return [];
            }
            return $body['ocs']['data'];
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai scheduled assignment list failed', ['url' => $url, 'user' => $userId, 'exception' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create a new scheduled assignment.
     *
     * @param string $userId
     * @param string $prompt The prompt to execute on schedule
     * @param string $recurrence RFC 5545 RRULE string (e.g. "FREQ=DAILY;INTERVAL=1")
     * @param int $startsAt Unix timestamp when to start
     * @param string $timezone Timezone name (e.g. "Europe/Berlin")
     * @return array{id:int,prompt:string,recurrence:string,startsAt:int,timezone:string}|null
     */
    public function createAssignment(string $userId, string $prompt, string $recurrence, int $startsAt, string $timezone = 'Europe/Berlin'): ?array {
        $url = $this->baseUrl . '/ocs/v1/appassistant/assignments';
        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'OCS-APIREQUEST' => 'true',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'prompt' => $prompt,
                    'recurrence' => $recurrence,
                    'startsAt' => $startsAt,
                    'timezone' => $timezone,
                ]),
            ]);
            $body = json_decode($response->getBody(), true);
            if (isset($body['ocs']['data']) && is_array($body['ocs']['data'])) {
                return $body['ocs']['data'];
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai scheduled assignment create failed', ['url' => $url, 'user' => $userId, 'exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Delete a scheduled assignment.
     *
     * @param string $userId
     * @param int $assignmentId
     * @return bool
     */
    public function deleteAssignment(string $userId, int $assignmentId): bool {
        $url = $this->baseUrl . '/ocs/v1/appassistant/assignments/' . $assignmentId;
        try {
            $response = $this->client->delete($url, [
                'headers' => [
                    'OCS-APIREQUEST' => 'true',
                    'Accept' => 'application/json',
                ],
            ]);
            $body = json_decode($response->getBody(), true);
            return isset($body['ocs']['meta']['statuscode']) && $body['ocs']['meta']['statuscode'] === 200;
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai scheduled assignment delete failed', ['url' => $url, 'user' => $userId, 'assignment' => $assignmentId, 'exception' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Update a scheduled assignment.
     *
     * @param string $userId
     * @param int $assignmentId
     * @param array{prompt?:string,recurrence?:string,startsAt?:int,timezone?:string} $updates
     * @return array{id:int,prompt:string,recurrence:string,startsAt:int,timezone:string}|null
     */
    public function updateAssignment(string $userId, int $assignmentId, array $updates): ?array {
        $url = $this->baseUrl . '/ocs/v1/appassistant/assignments/' . $assignmentId;
        try {
            $response = $this->client->patch($url, [
                'headers' => [
                    'OCS-APIREQUEST' => 'true',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($updates),
            ]);
            $body = json_decode($response->getBody(), true);
            if (isset($body['ocs']['data']) && is_array($body['ocs']['data'])) {
                return $body['ocs']['data'];
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai scheduled assignment update failed', ['url' => $url, 'user' => $userId, 'assignment' => $assignmentId, 'exception' => $e->getMessage()]);
            return null;
        }
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
