<?php

declare(strict_types=1);

namespace OCA\RagChat\Service;

use OCP\IDBConnection;

/**
 * Read-only access to the Nextcloud activity feed (oc_activity).
 * Returns recent activity for a user across all apps.
 */
class ActivityService {
    public function __construct(
        private IDBConnection $db
    ) {
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function recent(string $userId, array $args = []): array {
        $limit = max(1, min(100, (int)($args['limit'] ?? 25)));
        try {
            $stmt = $this->db->prepare(
                'SELECT `activity_id`,`timestamp`,`priority`,`app`,`type`,`subject`,`message`,`file`,`link`'
                . ' FROM `*PREFIX*activity`'
                . ' WHERE `affecteduser` = ? AND `app` <> ?'
                . ' ORDER BY `timestamp` DESC LIMIT ' . (int)$limit
            );
            $stmt->execute([$userId, 'ragchat']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Activity data not available: ' . $e->getMessage()];
        }
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int)$row['activity_id'],
                'time' => (int)$row['timestamp'],
                'date' => date('Y-m-d H:i', (int)$row['timestamp']),
                'app' => (string)$row['app'],
                'type' => (string)$row['type'],
                'subject' => (string)$row['subject'],
                'message' => (string)($row['message'] ?? ''),
                'file' => (string)($row['file'] ?? ''),
                'link' => (string)($row['link'] ?? ''),
            ];
        }
        return ['ok' => true, 'result' => ['activity' => $out]];
    }
}
