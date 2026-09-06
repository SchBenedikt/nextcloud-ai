<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Read-only access to the Nextcloud Mail app (accounts, mailboxes, messages).
 *
 * Works with Mail v5+ schema (flag_seen, preview_text, recipients table).
 * Mail availability is detected via query attempts (no schema introspection
 * which blocks in CLI context).
 */
class EmailService {
    /** Recipient type constants matching Mail v5. */
    private const RCV_FROM = 0;
    private const RCV_TO   = 1;
    private const RCV_CC   = 2;

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger
    ) {
    }

    // ------------------------------------------------------------------
    //  Public API
    // ------------------------------------------------------------------

    /**
     * All mail accounts of a user.
     *
     * @return list<array{id:int,email:string,displayName:string}>
     */
    public function accountsOf(string $userId): array {
        $rows = $this->q(
            'SELECT `id`, `email`, `name` FROM *PREFIX*mail_accounts WHERE `user_id` = ?',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => (int)$row['id'],
                'email'       => (string)($row['email'] ?? ''),
                'displayName' => (string)($row['name'] ?? $row['email'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Most recent emails across all user mailboxes.
     *
     * @return list<array{id:int,mailbox:string,subject:string,from:string,to:list<string>,preview:string,sent:int,unread:bool}>
     */
    public function listMessages(string $userId, int $limit = 15, bool $unreadOnly = false): array {
        $limit = max(1, min(100, $limit));
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($mailboxIds === []) {
            return [];
        }

        $whereMailbox = 'm.`mailbox_id` IN (' . implode(',', $mailboxIds) . ')';
        $extra = $unreadOnly ? ' AND m.`flag_seen` = 0' : '';

        $sql = "SELECT m.`id`, m.`mailbox_id`, m.`subject`, m.`sent_at`, m.`flag_seen`,
                       m.`preview_text`, m.`summary`
                FROM *PREFIX*mail_messages m
                WHERE {$whereMailbox}{$extra}
                ORDER BY m.`sent_at` DESC
                LIMIT " . (int)$limit;

        $rows = $this->q($sql, []);

        // Mailbox name cache
        $mailboxNames = $this->mailboxNamesByIds($mailboxIds);

        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $out[] = [
                'id'       => $id,
                'mailbox'  => $mailboxNames[(int)($row['mailbox_id'] ?? 0)] ?? '',
                'subject'  => (string)($row['subject'] ?? ''),
                'from'     => $this->senderOf($id),
                'to'       => $this->recipients($id, self::RCV_TO),
                'preview'  => (string)($row['preview_text'] ?? $row['summary'] ?? ''),
                'sent'     => (int)($row['sent_at'] ?? 0),
                'unread'   => (int)($row['flag_seen'] ?? 1) === 0,
            ];
        }
        return $out;
    }

    /**
     * All message IDs currently present in the user's mailboxes.
     * Used for RAG mail reconciliation (Issue #15): indexed mail documents
     * whose message no longer exists are removed from the index.
     * @return int[]
     */
    public function allMessageIds(string $userId): array {
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($mailboxIds === []) {
            return [];
        }
        $whereMailbox = 'm.`mailbox_id` IN (' . implode(',', $mailboxIds) . ')';
        $sql = "SELECT m.`id` FROM *PREFIX*mail_messages m WHERE {$whereMailbox}";
        $rows = $this->q($sql, []);
        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Read full message by id (DB content — preview + summary).
     * Returns IMAP body only if MailManager is available and works.
     *
     * @return array{ok:true,result:array}|array{ok:false,error:string}
     */
    public function readMessage(string $userId, int $messageId): array {
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($mailboxIds === []) {
            return ['ok' => false, 'error' => 'The Mail app is not available'];
        }

        $rows = $this->q(
            'SELECT `id`, `mailbox_id`, `subject`, `sent_at`, `flag_seen`,
                    `preview_text`, `summary`
             FROM *PREFIX*mail_messages WHERE `id` = ?',
            [$messageId]
        );
        if ($rows === []) {
            return ['ok' => false, 'error' => 'Message not found'];
        }
        $row = $rows[0];
        if (!in_array((int)($row['mailbox_id'] ?? 0), $mailboxIds, true)) {
            return ['ok' => false, 'error' => 'The message does not belong to this user'];
        }

        $body = trim((string)($row['preview_text'] ?? ''));
        if ($body === '') {
            $body = trim((string)($row['summary'] ?? ''));
        }

        return ['ok' => true, 'result' => [
            'id'       => $messageId,
            'subject'  => (string)($row['subject'] ?? ''),
            'from'     => $this->senderOf($messageId),
            'to'       => $this->recipients($messageId, self::RCV_TO),
            'cc'       => $this->recipients($messageId, self::RCV_CC),
            'date'     => date('Y-m-d H:i', (int)($row['sent_at'] ?? 0)),
            'preview'  => mb_substr($body, 0, 5000),
            'body'     => mb_substr($body, 0, 20000),
        ]];
    }

    /**
     * Search emails by subject, sender or preview.
     *
     * @return list<array{id:int,subject:string,from:string,preview:string,sent:int,unread:bool}>
     */
    public function search(string $userId, string $needle, int $limit = 10): array {
        $limit = max(1, min(100, $limit));
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($mailboxIds === []) {
            return [];
        }

        // Use a dedicated escape character instead of database-specific
        // backslash rules, so literal '%' and '_' behave consistently.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
        $mboxSql = implode(',', $mailboxIds);

        // Search subject + preview_text + recipients (sender name/email)
        $sql = "SELECT m.`id`, m.`subject`, m.`sent_at`, m.`flag_seen`,
                       m.`preview_text`
                FROM *PREFIX*mail_messages m
                LEFT JOIN *PREFIX*mail_recipients r
                    ON r.`message_id` = m.`id` AND r.`type` = " . self::RCV_FROM . "
                WHERE m.`mailbox_id` IN ({$mboxSql})
                  AND (m.`subject` LIKE ? ESCAPE '!'
                       OR m.`preview_text` LIKE ? ESCAPE '!'
                       OR r.`email` LIKE ? ESCAPE '!'
                       OR r.`label` LIKE ? ESCAPE '!')
                ORDER BY m.`sent_at` DESC
                LIMIT " . (int)$limit;

        $rows = $this->q($sql, [$like, $like, $like, $like]);

        // Deduplicate (JOIN may produce multiple rows per message)
        $seen = [];
        $out  = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id'      => $id,
                'subject' => (string)($row['subject'] ?? ''),
                'from'    => $this->senderOf($id),
                'preview' => (string)($row['preview_text'] ?? ''),
                'sent'    => (int)($row['sent_at'] ?? 0),
                'unread'  => (int)($row['flag_seen'] ?? 1) === 0,
            ];
        }
        return $out;
    }

    /**
     * Count unread mails across all user mailboxes.
     */
    public function unreadCount(string $userId): int {
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($mailboxIds === []) {
            return 0;
        }

        $mboxSql = implode(',', $mailboxIds);
        $r = $this->q(
            "SELECT COUNT(*) AS c FROM *PREFIX*mail_messages
             WHERE `mailbox_id` IN ({$mboxSql}) AND `flag_seen` = 0",
            []
        );
        return (int)($r[0]['c'] ?? 0);
    }

    /**
     * Raw text of a message (for the RAG indexer).
     */
    public function bodyText(int $messageId): string {
        $rows = $this->q(
            'SELECT `preview_text`, `summary` FROM *PREFIX*mail_messages WHERE `id` = ?',
            [$messageId]
        );
        if ($rows === []) {
            return '';
        }
        $row = $rows[0];
        $body = trim((string)($row['preview_text'] ?? ''));
        if ($body === '') {
            $body = trim((string)($row['summary'] ?? ''));
        }
        return $body;
    }

    /**
     * All mailboxes of a user.
     *
     * @return list<array{id:int,name:string}>
     */
    public function mailboxesOf(string $userId): array {
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($mailboxIds === []) {
            return [];
        }
        $names = $this->mailboxNamesByIds($mailboxIds);
        $out = [];
        foreach ($mailboxIds as $mid) {
            $out[] = ['id' => $mid, 'name' => $names[$mid] ?? (string)$mid];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    //  Internal helpers
    // ------------------------------------------------------------------

    /**
     * All mailbox IDs belonging to the user's accounts.
     *
     * @return list<int>
     */
    private function mailboxIdsOf(string $userId): array {
        $accounts = $this->accountsOf($userId);
        if ($accounts === []) {
            return [];
        }
        $accountIds = array_map(static fn(array $a): int => $a['id'], $accounts);
        $ids = [];
        foreach ($this->q(
            'SELECT `id` FROM *PREFIX*mail_mailboxes WHERE `account_id` IN (' . implode(',', $accountIds) . ')',
            []
        ) as $m) {
            $ids[] = (int)$m['id'];
        }
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function mailboxNamesByIds(array $ids): array {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->q(
            'SELECT `id`, `name` FROM *PREFIX*mail_mailboxes WHERE `id` IN (' . implode(',', $ids) . ')',
            []
        ) as $row) {
            $out[(int)$row['id']] = (string)($row['name'] ?? '');
        }
        return $out;
    }

    /**
     * Sender of a message from the recipients table (type = 0 = FROM).
     */
    private function senderOf(int $messageId): string {
        $rows = $this->q(
            'SELECT `email`, `label` FROM *PREFIX*mail_recipients
             WHERE `message_id` = ? AND `type` = ?',
            [$messageId, self::RCV_FROM]
        );
        if ($rows === []) {
            return '';
        }
        $email = (string)($rows[0]['email'] ?? '');
        $label = (string)($rows[0]['label'] ?? '');
        if ($email === '') {
            return '';
        }
        return ($label !== '' && $label !== $email) ? $label . ' <' . $email . '>' : $email;
    }

    /**
     * Recipients (to / cc) of a message.
     *
     * @return list<string>
     */
    private function recipients(int $messageId, int $type): array {
        $rows = $this->q(
            'SELECT `email`, `label` FROM *PREFIX*mail_recipients
             WHERE `message_id` = ? AND `type` = ?',
            [$messageId, $type]
        );
        $out = [];
        foreach ($rows as $r) {
            $email = (string)($r['email'] ?? '');
            $label = (string)($r['label'] ?? '');
            if ($email !== '') {
                $out[] = ($label !== '' && $label !== $email) ? $label . ' <' . $email . '>' : $email;
            }
        }
        return $out;
    }

    /**
     * Execute a prepared statement. Database failures are logged and
     * propagated so callers can distinguish an empty mailbox from a broken
     * Mail schema or database connection.
     *
     * @return list<array<string,mixed>>
     */
    private function q(string $sql, array $params): array {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll();
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai: Mail database query failed', [
                'exception' => $e,
                'sql' => mb_substr($sql, 0, 500),
            ]);
            throw $e;
        }
    }
}