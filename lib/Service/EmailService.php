<?php

declare(strict_types=1);

namespace OCA\RagChat\Service;

use OCP\IDBConnection;

/**
 * Read-only access to the Nextcloud Mail app (accounts, mailboxes, messages).
 *
 * The Mail app schema is detected at runtime so the app works with
 * Mail v2/v3. If Mail is not installed, methods return empty lists and
 * readMessage() reports "Mail app not available".
 */
class EmailService {
    public function __construct(
        private IDBConnection $db
    ) {
    }

    /** @return list<array{id:int,email:string,displayName:string}> */
    public function accountsOf(string $userId): array {
        if (!$this->hasTable('oc_mail_accounts')) {
            return [];
        }
        $out = [];
        foreach ($this->q("SELECT * FROM `*PREFIX*mail_accounts` WHERE `user_id` = ?", [$userId]) as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'email' => (string)($row['email'] ?? ''),
                'displayName' => (string)($row['account_name'] ?? $row['email'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return list<int> */
    private function mailboxIdsOf(string $userId): array {
        $mailboxes = $this->mailboxesTable();
        if ($mailboxes === null) {
            return [];
        }
        $ids = [];
        foreach ($this->accountsOf($userId) as $acc) {
            foreach ($this->q("SELECT `id` FROM `$mailboxes` WHERE `account_id` = ?", [(int)$acc['id']]) as $m) {
                $ids[] = (int)$m['id'];
            }
        }
        return $ids;
    }

    /** @return list<array{id:int,mailbox:string,subject:string,from:string,to:list<string>,preview:string,sent:int,unread:bool}> */
    public function listMessages(string $userId, int $limit = 15, bool $unreadOnly = false): array {
        $mailboxes = $this->mailboxesTable();
        $messages = $this->messagesTable();
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($mailboxes === null || $messages === null || $mailboxIds === []) {
            return [];
        }
        $rows = $this->q(
            "SELECT * FROM `$messages` WHERE `mailbox_id` IN (" . implode(',', $mailboxIds) . ')'
            . ($unreadOnly ? ' AND `seen` = 0' : '')
            . ' ORDER BY `sent_at` DESC LIMIT ' . (int)$limit,
            []
        );
        $names = $this->mailboxNames($mailboxIds, $mailboxes);
        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $sent = (int)($row['sent_at'] ?? 0);
            $out[] = [
                'id' => $id,
                'mailbox' => $names[(int)($row['mailbox_id'] ?? 0)] ?? '',
                'subject' => (string)($row['subject'] ?? ''),
                'from' => (string)($row['from_email'] ?? ''),
                'to' => $this->recipients($id, 'to'),
                'preview' => (string)($row['preview'] ?? ''),
                'sent' => $sent,
                'unread' => (int)($row['seen'] ?? 1) === 0,
            ];
        }
        return $out;
    }

    /** @return array{ok:true,result:array}|array{ok:false,error:string} */
    public function readMessage(string $userId, int $messageId): array {
        $messages = $this->messagesTable();
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($messages === null || $mailboxIds === []) {
            return ['ok' => false, 'error' => 'Mail app not available'];
        }
        $rows = $this->q("SELECT * FROM `$messages` WHERE `id` = ?", [$messageId]);
        if ($rows === []) {
            return ['ok' => false, 'error' => 'Message not found'];
        }
        $row = $rows[0];
        if (!in_array((int)($row['mailbox_id'] ?? 0), $mailboxIds, true)) {
            return ['ok' => false, 'error' => 'Message not found (not yours)'];
        }
        $body = '';
        foreach (['body_text', 'body', 'content'] as $col) {
            if (isset($row[$col]) && is_string($row[$col]) && trim($row[$col]) !== '') {
                $body = (string)$row[$col];
                break;
            }
        }
        if (isset($row['body_html']) && trim((string)$row['body_html']) !== '') {
            $html = (string)$row['body_html'];
            $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
            $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
            $html = preg_replace('/<[^>]+>/', ' ', $html ?? '');
            $body = html_entity_decode($html ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return ['ok' => true, 'result' => [
            'id' => $messageId,
            'subject' => (string)($row['subject'] ?? ''),
            'from' => (string)($row['from_email'] ?? ''),
            'to' => $this->recipients($messageId, 'to'),
            'cc' => $this->recipients($messageId, 'cc'),
            'date' => date('Y-m-d H:i', (int)($row['sent_at'] ?? 0)),
            'preview' => (string)($row['preview'] ?? ''),
            'body' => trim($body) !== '' ? mb_substr($body, 0, 20000) : '',
        ]];
    }

    /** @return list<array{id:int,subject:string,from:string,preview:string,sent:int,unread:bool}> */
    public function search(string $userId, string $needle, int $limit = 10): array {
        $messages = $this->messagesTable();
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($messages === null || $mailboxIds === []) {
            return [];
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%';
        $rows = $this->q(
            "SELECT * FROM `$messages` WHERE `mailbox_id` IN (" . implode(',', $mailboxIds) . ')
             AND (`subject` LIKE ? OR `preview` LIKE ? OR `from_email` LIKE ?)
             ORDER BY `sent_at` DESC LIMIT ' . (int)$limit,
            [$like, $like, $like]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'subject' => (string)($row['subject'] ?? ''),
                'from' => (string)($row['from_email'] ?? ''),
                'preview' => (string)($row['preview'] ?? ''),
                'sent' => (int)($row['sent_at'] ?? 0),
                'unread' => (int)($row['seen'] ?? 1) === 0,
            ];
        }
        return $out;
    }

    /** How many unread mails across all mailboxes. */
    public function unreadCount(string $userId): int {
        $messages = $this->messagesTable();
        $mailboxIds = $this->mailboxIdsOf($userId);
        if ($messages === null || $mailboxIds === []) {
            return 0;
        }
        $r = $this->q(
            "SELECT COUNT(*) AS c FROM `$messages` WHERE `mailbox_id` IN (" . implode(',', $mailboxIds) . ') AND `seen` = 0',
            []
        );
        return (int)($r[0]['c'] ?? 0);
    }

    /** Raw text of a message (for the RAG indexer). */
    public function bodyText(int $messageId): string {
        $messages = $this->messagesTable();
        if ($messages === null) {
            return '';
        }
        $rows = $this->q("SELECT * FROM `$messages` WHERE `id` = ?", [$messageId]);
        if ($rows === []) {
            return '';
        }
        $row = $rows[0];
        foreach (['body_text', 'body', 'content'] as $col) {
            if (isset($row[$col]) && is_string($row[$col]) && trim($row[$col]) !== '') {
                return (string)$row[$col];
            }
        }
        return '';
    }

    /** @return list<array{id:int,name:string}> */
    public function mailboxesOf(string $userId): array {
        $mailboxes = $this->mailboxesTable();
        if ($mailboxes === null) {
            return [];
        }
        $out = [];
        foreach ($this->mailboxIdsOf($userId) as $mid) {
            $rows = $this->q("SELECT `id`, `name` FROM `$mailboxes` WHERE `id` = ?", [$mid]);
            if ($rows !== []) {
                $out[] = ['id' => $mid, 'name' => (string)($rows[0]['name'] ?? '')];
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int,string> */
    private function mailboxNames(array $ids, string $table): array {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->q("SELECT `id`, `name` FROM `$table` WHERE `id` IN (" . implode(',', $ids) . ')', []) as $row) {
            $out[(int)$row['id']] = (string)($row['name'] ?? '');
        }
        return $out;
    }

    /** @return list<string> */
    private function recipients(int $messageId, string $type): array {
        if (!$this->hasTable('oc_mail_recipients')) {
            return [];
        }
        $out = [];
        foreach ($this->q("SELECT `email`, `label` FROM `*PREFIX*mail_recipients` WHERE `message_id` = ? AND `type` = ?", [$messageId, $type]) as $r) {
            $email = (string)($r['email'] ?? '');
            $label = (string)($r['label'] ?? '');
            if ($email !== '') {
                $out[] = ($label !== '' && $label !== $email) ? $label . ' <' . $email . '>' : $email;
            }
        }
        return $out;
    }

    private function mailboxesTable(): ?string {
        foreach (['oc_mail_mailboxes', 'oc_mailboxes'] as $t) {
            if ($this->hasTable($t)) {
                return $t;
            }
        }
        return null;
    }

    private function messagesTable(): ?string {
        return $this->hasTable('oc_mail_messages') ? 'oc_mail_messages' : null;
    }

    private function hasTable(string $name): bool {
        try {
            return $this->db->schema()->hasTable($name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    private function q(string $sql, array $params): array {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
