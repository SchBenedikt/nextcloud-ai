<?php

declare(strict_types=1);
namespace OCA\EvaAi\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/** Database-backed, user-scoped conversations. Legacy AppData is copied once. */
class ChatStore {
    public function __construct(
        private IDBConnection $db,
        private ILockingProvider $locks,
        private LegacyChatStore $legacy,
    ) {}

    private function locked(string $user, callable $fn): mixed {
        $key = 'eva_ai/chats/' . hash('sha256', $user);
        $this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->db->beginTransaction();
            try {
                $this->migrate($user);
                $result = $fn();
                $this->db->commit();
                return $result;
            } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        } finally { $this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE); }
    }

    private function migrate(string $user): void {
        if ($this->item($user, 'migration', 'appdata') !== null) { return; }
        foreach ($this->legacy->list($user) as $summary) {
            $chat = $this->legacy->get($user, $summary['id']);
            if ($chat === null || $this->item($user, 'chat', $chat['id']) !== null) { continue; }
            $messages = $chat['messages'] ?? [];
            if (!is_array($messages)) { throw new \RuntimeException('Malformed legacy chat messages'); }
            unset($chat['messages']);
            $chat['count'] = count($messages);
            $this->saveItem($user, 'chat', $chat);
            foreach ($messages as $m) { $this->insertMessage($user, $chat['id'], (string)$m['role'], (string)$m['text']); }
        }
        $this->saveItem($user, 'migration', ['id' => 'appdata']);
    }

    private function scope(string $user, string $kind, ?string $id = null): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('eva_ai_chat_items')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))
            ->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
        if ($id !== null) { $qb->andWhere($qb->expr()->eq('item_id', $qb->createNamedParameter($id))); }
        return $qb;
    }

    private function item(string $user, string $kind, string $id): ?array {
        $result = $this->scope($user, $kind, $id)->executeQuery();
        try { $row = $result->fetch(); return $row ? json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR) : null; }
        finally { $result->closeCursor(); }
    }

    private function saveItem(string $user, string $kind, array $item): void {
        $existing = $this->item($user, $kind, $item['id']);
        $qb = $this->db->getQueryBuilder();
        $values = ['user_id' => $user, 'kind' => $kind, 'item_id' => $item['id'],
            'title' => mb_substr((string)($item['title'] ?? ''), 0, 255),
            'project_id' => (string)($item['project'] ?? ''), 'archived' => (int)($item['archived'] ?? false), 'pinned' => (int)($item['pinned'] ?? false),
            'data' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => time()];
        if ($existing === null) {
            $qb->insert('eva_ai_chat_items');
            foreach ($values as $key => $value) { $qb->setValue($key, $qb->createNamedParameter($value)); }
        } else {
            $qb->update('eva_ai_chat_items');
            foreach (['title', 'data', 'updated_at', 'project_id', 'archived', 'pinned'] as $key) { $qb->set($key, $qb->createNamedParameter($values[$key])); }
            foreach (['user_id', 'kind', 'item_id'] as $key) { $qb->andWhere($qb->expr()->eq($key, $qb->createNamedParameter($values[$key]))); }
        }
        $qb->executeStatement();
    }

    /** Metadata only: message bodies are searched on the server, never sent as histories. */
    public function list(string $user, string $search = '', int $limit = 100, int $offset = 0, ?string $project = null, bool $archived = false): array {
        return $this->locked($user, function () use ($user, $search, $limit, $offset, $project, $archived): array {
            $qb = $this->scope($user, 'chat');
            if ($search !== '') {
                $like = $qb->createNamedParameter('%' . $qb->escapeLikeParameter(mb_strtolower(mb_substr($search, 0, 200))) . '%');
                $sub = $this->db->getQueryBuilder();
                $sub->select('chat_id')->from('eva_ai_messages')
                    ->where($sub->expr()->eq('user_id', $sub->createNamedParameter($user, IQueryBuilder::PARAM_STR, ':searchuser')))
                    ->andWhere($sub->expr()->like($sub->func()->lower('content'), $sub->createNamedParameter('%' . $sub->escapeLikeParameter(mb_strtolower(mb_substr($search, 0, 200))) . '%', IQueryBuilder::PARAM_STR, ':searchtext')));
                $qb->andWhere($qb->expr()->orX($qb->expr()->like($qb->func()->lower('title'), $like), $qb->expr()->in('item_id', $qb->createFunction($sub->getSQL()))));
                $qb->setParameter('searchuser', $user);
                $qb->setParameter('searchtext', '%' . $qb->escapeLikeParameter(mb_strtolower(mb_substr($search, 0, 200))) . '%');
            }
            $qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter((int)$archived, IQueryBuilder::PARAM_INT)));
            if ($project !== null) { $qb->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($project))); }
            if ($search !== '') {
                $exact = $qb->createNamedParameter(mb_strtolower($search));
                $qb->orderBy($qb->createFunction('CASE WHEN LOWER(title) = ' . $exact . ' THEN 0 ELSE 1 END'), 'ASC');
            }
            $result = $qb->addOrderBy('pinned', 'DESC')->addOrderBy('updated_at', 'DESC')->addOrderBy('item_id', 'ASC')
                ->setMaxResults(max(1, min(100, $limit)))->setFirstResult(max(0, $offset))->executeQuery();
            $out = [];
            try {
                while ($row = $result->fetch()) { $out[] = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR); }
            } finally { $result->closeCursor(); }
            foreach ($out as &$item) {
                if ($search !== '') { $item['snippet'] = $this->snippet($user, $item['id'], $search); }
            }
            unset($item);
            return $out;
        });
    }

    private function snippet(string $user, string $id, string $search): string {
        $qb = $this->messagesQuery($user, $id);
        $qb->andWhere($qb->expr()->like($qb->func()->lower('content'), $qb->createNamedParameter('%' . $qb->escapeLikeParameter(mb_strtolower($search)) . '%')))->setMaxResults(1);
        $r = $qb->executeQuery();
        try { $row = $r->fetch(); $text = (string)($row['content'] ?? ''); }
        finally { $r->closeCursor(); }
        $pos = mb_stripos($text, $search);
        return mb_substr($text, max(0, (int)$pos - 60), 220);
    }

    private function messagesQuery(string $user, string $id): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();
        return $qb->select('id', 'role', 'content')->from('eva_ai_messages')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))
            ->andWhere($qb->expr()->eq('chat_id', $qb->createNamedParameter($id)))->orderBy('id', 'ASC');
    }

    public function get(string $user, string $id, int $limit = 100, int $offset = -1): ?array {
        return $this->locked($user, function () use ($user, $id, $limit, $offset): ?array {
            $chat = $this->item($user, 'chat', $id);
            if ($chat === null) { return null; }
            $limit = max(1, min(200, $limit));
            $offset = $offset < 0 ? max(0, ($chat['count'] ?? 0) - $limit) : $offset;
            $r = $this->messagesQuery($user, $id)->setMaxResults($limit)->setFirstResult($offset)->executeQuery();
            try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }
            $chat['messages'] = array_map(static fn($m) => ['id' => (int)$m['id'], 'role' => $m['role'], 'text' => $m['content']], $rows);
            $chat['offset'] = $offset;
            $chat['total'] = (int)($chat['count'] ?? 0);
            $chat['hasEarlier'] = $offset > 0;
            return $chat;
        });
    }

    public function create(string $user, ?string $title = null): array {
        return $this->locked($user, function () use ($user, $title): array {
            $chat = ['id' => bin2hex(random_bytes(16)), 'title' => mb_substr(trim($title ?? '') ?: 'Neuer Chat', 0, 60),
                'created' => time(), 'updated' => time(), 'count' => 0, 'messages' => []];
            $this->saveItem($user, 'chat', $chat);
            return $chat;
        });
    }

    private function insertMessage(string $user, string $id, string $role, string $text): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('eva_ai_messages');
        foreach (['user_id' => $user, 'chat_id' => $id, 'role' => $role, 'content' => $text] as $k => $v) { $qb->setValue($k, $qb->createNamedParameter($v)); }
        $qb->executeStatement();
    }

    public function append(string $user, string $id, string $role, string $text): void {
        if (!in_array($role, ['user', 'assistant'], true) || mb_strlen($text) > 1000000) { throw new \InvalidArgumentException('Invalid message'); }
        $this->locked($user, function () use ($user, $id, $role, $text): void {
            $chat = $this->item($user, 'chat', $id);
            if ($chat === null) { throw new \InvalidArgumentException('Chat not found'); }
            $this->insertMessage($user, $id, $role, $text);
            if (($chat['count'] ?? 0) === 0 && $chat['title'] === 'Neuer Chat') { $chat['title'] = mb_substr($text, 0, 60); }
            $chat['count'] = ($chat['count'] ?? 0) + 1;
            $chat['updated'] = time();
            $this->saveItem($user, 'chat', $chat);
        });
    }

    public function setTitle(string $user, string $id, string $title): void { $this->update($user, $id, ['title' => $title]); }

    public function update(string $user, string $id, array $changes): array {
        return $this->locked($user, function () use ($user, $id, $changes): array {
            $chat = $this->item($user, 'chat', $id);
            if ($chat === null) { throw new \InvalidArgumentException('Chat not found'); }
            foreach (['title' => 60, 'instructions' => 4000, 'persona' => 40, 'project' => 64] as $key => $max) {
                if (isset($changes[$key])) { $chat[$key] = mb_substr(trim((string)$changes[$key]), 0, $max); }
            }
            if (!empty($chat['project']) && $this->item($user, 'project', $chat['project']) === null) { throw new \InvalidArgumentException('Project not found'); }
            foreach (['pinned', 'archived'] as $key) { if (isset($changes[$key])) { $chat[$key] = filter_var($changes[$key], FILTER_VALIDATE_BOOLEAN); } }
            $chat['updated'] = time();
            $this->saveItem($user, 'chat', $chat);
            return $chat;
        });
    }

    private function remove(string $user, string $kind, ?string $id = null): int {
        $qb = $this->scope($user, $kind, $id);
        $qb->delete('eva_ai_chat_items');
        return $qb->executeStatement();
    }

    public function delete(string $user, string $id): bool {
        return $this->locked($user, function () use ($user, $id): bool {
            $qb = $this->messagesQuery($user, $id); $qb->delete('eva_ai_messages'); $qb->resetQueryPart('orderBy'); $qb->executeStatement();
            return $this->remove($user, 'chat', $id) > 0;
        });
    }

    public function deleteAll(string $user): int {
        return $this->locked($user, function () use ($user): int {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('eva_ai_messages')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))->executeStatement();
            return $this->remove($user, 'chat');
        });
    }

    /** Copy through a selected message; edits/regeneration never destroy the original. */
    public function branch(string $user, string $id, int $messageId, ?string $replacement = null): array {
        return $this->locked($user, function () use ($user, $id, $messageId, $replacement): array {
            $chat = $this->item($user, 'chat', $id);
            if ($chat === null) { throw new \InvalidArgumentException('Chat not found'); }
            if ($replacement !== null && (trim($replacement) === '' || mb_strlen($replacement) > 100000)) { throw new \InvalidArgumentException('Invalid replacement'); }
            $qb = $this->messagesQuery($user, $id);
            $qb->andWhere($qb->expr()->lte('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));
            $r = $qb->executeQuery();
            $newId = bin2hex(random_bytes(16)); $count = 0; $found = false;
            try {
                while ($m = $r->fetch()) {
                    $last = (int)$m['id'] === $messageId;
                    if ($last && $replacement !== null && $m['role'] !== 'user') { throw new \InvalidArgumentException('Only user messages can be edited'); }
                    $this->insertMessage($user, $newId, $m['role'], $last && $replacement !== null ? $replacement : $m['content']);
                    $count++; $found = $found || $last;
                }
            } finally { $r->closeCursor(); }
            if (!$found) { throw new \InvalidArgumentException('Message not found'); }
            $chat = array_merge($chat, ['id' => $newId, 'parent' => $id, 'count' => $count, 'created' => time(), 'updated' => time(), 'archived' => false]);
            $this->saveItem($user, 'chat', $chat);
            return $chat;
        });
    }

    public function projects(string $user): array {
        return $this->locked($user, function () use ($user): array {
            $r = $this->scope($user, 'project')->executeQuery();
            try { $items = array_map(static fn($r) => json_decode($r['data'], true), $r->fetchAll()); }
            finally { $r->closeCursor(); }
            usort($items, static fn($a, $b) => ($a['position'] <=> $b['position']) ?: strcmp($a['title'], $b['title']));
            return $items;
        });
    }

    public function saveProject(string $user, array $data): array {
        return $this->locked($user, function () use ($user, $data): array {
            $id = (string)($data['id'] ?? '');
            $item = $id !== '' ? $this->item($user, 'project', $id) : ['id' => bin2hex(random_bytes(16))];
            if ($item === null) { throw new \InvalidArgumentException('Project not found'); }
            $item['title'] = mb_substr(trim((string)($data['title'] ?? $item['title'] ?? '')), 0, 60);
            if ($item['title'] === '') { throw new \InvalidArgumentException('Project name required'); }
            foreach (['description' => 1000, 'color' => 32, 'icon' => 32] as $key => $max) { $item[$key] = mb_substr((string)($data[$key] ?? $item[$key] ?? ''), 0, $max); }
            $item['archived'] = filter_var($data['archived'] ?? $item['archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $item['position'] = max(0, min(10000, (int)($data['position'] ?? $item['position'] ?? 0)));
            $this->saveItem($user, 'project', $item);
            return $item;
        });
    }

    public function deleteProject(string $user, string $id): void {
        $this->locked($user, function () use ($user, $id): void {
            $r = $this->scope($user, 'chat')->executeQuery();
            try { $rows = $r->fetchAll(); } finally { $r->closeCursor(); }
            foreach ($rows as $row) {
                $chat = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                if (($chat['project'] ?? '') === $id) { $chat['project'] = ''; $this->saveItem($user, 'chat', $chat); }
            }
            $this->remove($user, 'project', $id);
        });
    }
}
