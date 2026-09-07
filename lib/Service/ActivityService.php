<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IDBConnection;

/**
 * Read-only access to the Nextcloud activity feed.
 *
 * EVA must never be coupled to the Activity app's private schema, so the feed
 * is only read when the Activity app is enabled for the requesting user and
 * its table actually exists. All column access is defensive (SELECT * + null
 * fallbacks) so a rename inside the Activity app degrades to a missing field
 * instead of a hard SQL error. The raw query is deliberately kept only as a
 * fallback for environments where the Activity app's own services are not
 * reachable; the returned dates are formatted in the requesting user's
 * timezone, never the server timezone (Issue #113).
 */
class ActivityService {
    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private IAppManager $appManager,
        private IDateTimeFormatter $dateTimeFormatter
    ) {
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function recent(string $userId, array $args = []): array {
        $limit = max(1, min(100, (int)($args['limit'] ?? 25)));
        if ($userId === '') {
            return ['ok' => false, 'error' => 'No user given'];
        }
        if (!$this->activityAvailable($userId)) {
            return ['ok' => false, 'error' => 'The Nextcloud Activity app is not enabled for this user.'];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT *'
                . ' FROM `*PREFIX*activity`'
                . ' WHERE `affecteduser` = ?'
                . ' ORDER BY `timestamp` DESC, `activity_id` DESC LIMIT ' . (int)$limit
            );
            $stmt->execute([$userId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Activity data not available: ' . $e->getMessage()];
        }
        $timezone = $this->userTimeZone($userId);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $timestamp = (int)($row['timestamp'] ?? 0);
            $out[] = [
                'id' => (int)($row['activity_id'] ?? 0),
                'time' => $timestamp,
                'date' => $timestamp > 0
                    ? $this->dateTimeFormatter->formatDateTime($timestamp, 'medium', 'short', $timezone, null)
                    : '',
                'app' => (string)($row['app'] ?? ''),
                'type' => (string)($row['type'] ?? ''),
                'subject' => (string)($row['subject'] ?? ''),
                'message' => (string)($row['message'] ?? ''),
                'file' => (string)($row['file'] ?? ''),
                'link' => (string)($row['link'] ?? ''),
            ];
        }
        return ['ok' => true, 'result' => ['activity' => $out]];
    }

    /**
     * Only query the activity feed when the Activity app is enabled for the
     * user and the underlying table exists. This keeps EVA independent of
     * schema changes and cleanly degrades when the app is disabled.
     */
    private function activityAvailable(string $userId): bool {
        try {
            if (!$this->appManager->isEnabledForUser('activity', $userId)) {
                return false;
            }
            $schema = $this->db->getSchemaWrapper();
            if ($schema !== null && method_exists($schema, 'hasTable')) {
                return $schema->hasTable('activity');
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function userTimeZone(string $userId): \DateTimeZone {
        $configured = (string)$this->config->getUserValue($userId, 'core', 'timezone', '');
        if ($configured !== '') {
            try {
                return new \DateTimeZone($configured);
            } catch (\Throwable $e) {
                // Fall through to the server default.
            }
        }
        try {
            return new \DateTimeZone(date_default_timezone_get());
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }
}
