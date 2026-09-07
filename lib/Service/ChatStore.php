<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Persistiert Chats pro Benutzer als JSON im AppData-Verzeichnis.
 * Jeder Chat: {id, title, created, updated, messages:[{role,text}]}
 *
 * User namespaces are derived from a SHA-256 hash of the exact Nextcloud user
 * ID, so distinct IDs (LDAP/SSO formats like `john@example.com`) can never
 * collide (Issue #8). Legacy folders created by the old lossy slug are still
 * read for backwards compatibility and migrated lazily.
 */
class ChatStore {
    // How many messages of one conversation are kept. Older messages are not
    // silently destroyed: every drop is counted on the chat so the UI can tell
    // the user that the beginning of the conversation was trimmed (Issue #96).
    private const MAX_MESSAGES = 1000;
    private const MAX_TITLE = 60;
    private const SNIPPET_RADIUS = 60;

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private LoggerInterface $logger,
        private ILockingProvider $lockingProvider
    ) {
    }

    /**
     * List the user's chats, newest first.
     *
     * With $search the list is filtered to chats whose title or any message
     * contains the needle (case-insensitive); message hits add a short snippet
     * around the first match and a count of matching messages, so the chat list
     * can find content, not only titles (Issue #152).
     *
     * @return list<array{id:string,title:string,created:int,updated:int,count:int,snippet?:string,matchCount?:int,trimmed?:int}>
     */
    public function list(string $user, ?string $search = null): array {
        return $this->withUserLock($user, function () use ($user, $search): array {
            $all = $this->read($user);
            $needle = $search !== null ? mb_strtolower(trim($search)) : '';
            $out = [];
            foreach ($all as $chat) {
                $messages = $chat['messages'] ?? [];
                $entry = [
                    'id' => $chat['id'] ?? '',
                    'title' => $chat['title'] ?? 'Neuer Chat',
                    'created' => $chat['created'] ?? 0,
                    'updated' => $chat['updated'] ?? 0,
                    'count' => count($messages),
                    'trimmed' => (int)($chat['trimmed'] ?? 0),
                ];
                if ($needle !== '') {
                    $titleHit = mb_strpos(mb_strtolower($entry['title']), $needle) !== false;
                    $snippet = null;
                    $matchCount = 0;
                    foreach ($messages as $m) {
                        $text = (string)($m['text'] ?? '');
                        if ($text === '' || mb_stripos($text, $needle) === false) {
                            continue;
                        }
                        $matchCount++;
                        if ($snippet === null) {
                            $snippet = $this->snippetAround($text, $needle);
                        }
                    }
                    if (!$titleHit && $matchCount === 0) {
                        continue; // No hit in title or content.
                    }
                    if ($matchCount > 0) {
                        $entry['snippet'] = $snippet ?? '';
                        $entry['matchCount'] = $matchCount;
                    }
                }
                $out[] = $entry;
            }
            usort($out, static fn($a, $b) => $b['updated'] <=> $a['updated']);
            return $out;
        });
    }

    /** @return array|null */
    public function get(string $user, string $id): ?array {
        return $this->withUserLock($user, function () use ($user, $id): ?array {
            foreach ($this->read($user) as $chat) {
                if (($chat['id'] ?? '') === $id) {
                    return $chat;
                }
            }
            return null;
        });
    }

    public function create(string $user, ?string $title = null): array {
        return $this->withUserLock($user, function () use ($user, $title): array {
            // Keine doppelten leeren Chats: ein noch leerer Chat wird wiederverwendet.
            $all = $this->read($user);
            foreach ($all as $existing) {
                if (count($existing['messages'] ?? []) === 0) {
                    $existing['reused'] = true;
                    return $existing;
                }
            }
            $chat = [
                'id' => 'c' . date('YmdHis') . '-' . bin2hex(random_bytes(4)),
                'title' => $title !== null && $title !== '' ? $this->clipTitle($title) : 'Neuer Chat',
                'created' => time(),
                'updated' => time(),
                'messages' => [],
            ];
            $all[] = $chat;
            $this->write($user, $all);
            return $chat;
        });
    }

    public function delete(string $user, string $id): bool {
        return $this->withUserLock($user, function () use ($user, $id): bool {
            $all = $this->read($user);
            $kept = array_values(array_filter($all, static fn($c) => ($c['id'] ?? '') !== $id));
            if (count($kept) === count($all)) {
                return false;
            }
            $this->write($user, $kept);
            return true;
        });
    }

    /**
     * Delete every saved chat belonging to one user and return the number
     * removed. The operation is serialized with the other chat mutations.
     */
    public function deleteAll(string $user): int {
        return $this->withUserLock($user, function () use ($user): int {
            $all = $this->read($user);
            $deleted = count($all);
            if ($deleted > 0) {
                $this->write($user, []);
            }
            return $deleted;
        });
    }

    /**
     * Full export of every saved chat (messages included), used by the GDPR
     * data-export endpoint (Issue #83). Serialized like every other chat read.
     *
     * @return list<array<string,mixed>>
     */
    public function exportAll(string $user): array {
        return $this->withUserLock($user, function () use ($user): array {
            return $this->read($user);
        });
    }

    /**
     * Remove the user's complete chat storage (hashed + legacy folders) when
     * their account is deleted (Issue #83).
     */
    public function deleteUserData(string $user): void {
        $this->withUserLock($user, function () use ($user): void {
            try {
                $appdata = $this->appDataFactory->get('eva_ai');
                $chats = $appdata->getFolder('chats');
                $ns = $this->namespaceFor($user);
                foreach ([$ns, $this->legacySlug($user)] as $candidate) {
                    try {
                        $chats->getFolder($candidate)->delete();
                    } catch (NotFoundException $e) {
                        // No folder for this candidate - fine.
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('eva_ai: chat cleanup failed for deleted account', ['user' => $user]);
            }
        });
    }

    public function setTitle(string $user, string $id, string $title): void {
        $this->withUserLock($user, function () use ($user, $id, $title): void {
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') === $id) {
                    $chat['title'] = $this->clipTitle($title);
                    $chat['updated'] = time();
                    break;
                }
            }
            unset($chat);
            $this->write($user, $all);
        });
    }

    public function append(string $user, string $id, string $role, string $text): void {
        if ($role !== 'user' && $role !== 'assistant') {
            return;
        }
        $this->withUserLock($user, function () use ($user, $id, $role, $text): void {
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') === $id) {
                    $chat['messages'][] = ['role' => $role, 'text' => $text];
                    $chat['updated'] = time();
                    $messageCount = count($chat['messages']);
                    if ($messageCount > self::MAX_MESSAGES) {
                        $dropped = $messageCount - self::MAX_MESSAGES;
                        $chat['messages'] = array_slice($chat['messages'], -self::MAX_MESSAGES);
                        // Keep a durable counter so truncation is never silent.
                        $chat['trimmed'] = (int)($chat['trimmed'] ?? 0) + $dropped;
                    }
                    if (isset($chat['messages'][0]['text']) && str_starts_with($chat['title'] ?? '', 'Neuer Chat')) {
                        $chat['title'] = $this->clipTitle((string)$chat['messages'][0]['text']);
                    }
                    break;
                }
            }
            unset($chat);
            $this->write($user, $all);
        });
    }

    /** @return list<array{id:string,title:string,created:int,updated:int,messages:list<array{role:string,text:string}>}> */
    private function read(string $user): array {
        try {
            $raw = $this->rootFor($user)->getContent();
        } catch (NotFoundException $e) {
            return [];
        } catch (NotPermittedException $e) {
            $this->logger->warning('eva_ai: chat folder not readable (permissions?)', ['user' => $user]);
            throw $e;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function write(string $user, array $data): void {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->rootFor($user)->putContent($json);
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai: chat save failed - chats may disappear after reload', [
                'user' => $user,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Serialize all chat reads and mutations for one user across the whole
     * cluster. A node-local flock() in the temp directory let two app servers
     * race on the same chats.json, silently losing updates on clustered
     * deployments (Issue #78). Nextcloud's locking provider coordinates via a
     * shared backend (database or distributed cache), so the read-modify-write
     * of one user's chat file is atomic on every node.
     */
    private function withUserLock(string $user, callable $operation): mixed {
        $lockPath = 'eva_ai/chat/' . $this->namespaceFor($user);
        try {
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: chat lock could not be acquired', [
                'user' => $user,
                'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Unable to acquire the EVA chat lock');
        }
        try {
            return $operation();
        } finally {
            try {
                $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Throwable $e) {
                // A lock that was already released (e.g. expired TTL on a
                // crashed node) must not mask the operation's own result.
            }
        }
    }

    private function rootFor(string $user): \OCP\Files\SimpleFS\ISimpleFile {
        $appdata = $this->appDataFactory->get('eva_ai');
        try {
            $chats = $appdata->getFolder('chats');
        } catch (NotFoundException $e) {
            $chats = $appdata->newFolder('chats');
        }
        $this->copyLegacyChatData($chats);
        $uidFolder = $this->folderFor($chats, $user);
        if (!$uidFolder->fileExists('chats.json')) {
            $uidFolder->newFile('chats.json', '[]');
        }
        return $uidFolder->getFile('chats.json');
    }

    /**
     * Copy chat folders from legacy app IDs when they are still available.
     * Existing current folders always win; the old namespace is retained for
     * rollback safety and can be removed after an administrator verifies it.
     */
    private function copyLegacyChatData(ISimpleFolder $targetChats): void {
        foreach (['eva-ai', 'ragchat'] as $legacyAppId) {
            try {
                $legacyChats = $this->appDataFactory->get($legacyAppId)->getFolder('chats');
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($legacyChats->getDirectoryListing() as $legacyUserFolder) {
                try {
                    $targetUserFolder = $targetChats->getFolder($legacyUserFolder->getName());
                } catch (NotFoundException $e) {
                    try {
                        $targetUserFolder = $targetChats->newFolder($legacyUserFolder->getName());
                    } catch (\Throwable $copyError) {
                        continue;
                    }
                }
                foreach ($legacyUserFolder->getDirectoryListing() as $entry) {
                    try {
                        if (!$targetUserFolder->fileExists($entry->getName())) {
                            $targetUserFolder->newFile($entry->getName(), $entry->getContent());
                        }
                    } catch (\Throwable $copyError) {
                        $this->logger->warning('eva_ai: legacy chat copy skipped', [
                            'file' => $entry->getName(),
                            'exception' => $copyError->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Resolve (and lazily migrate) the per-user namespace folder.
     *
     * Prefers the SHA-256 namespace. If it does not exist yet but the legacy
     * lossy-slug folder does (data created before the fix), the legacy folder
     * is used and migrated to the hashed name so no data is lost and the
     * collision-free namespace is authoritative from now on.
     */
    private function folderFor(ISimpleFolder $chats, string $user): ISimpleFolder {
        $ns = $this->namespaceFor($user);
        try {
            return $chats->getFolder($ns);
        } catch (NotFoundException $e) {
            // No hashed folder yet - check for legacy slug data.
        }
        $legacy = $this->legacySlug($user);
        try {
            $legacyFolder = $chats->getFolder($legacy);
            // Migrate: move the legacy folder to the collision-free namespace.
            try {
                $newFolder = $chats->newFolder($ns);
                foreach ($legacyFolder->getDirectoryListing() as $entry) {
                    $newFolder->newFile($entry->getName(), $entry->getContent());
                }
                $legacyFolder->delete();
                $this->logger->info('eva_ai: migrated chat namespace', ['user' => $user, 'from' => $legacy, 'to' => $ns]);
                return $newFolder;
            } catch (\Throwable $migErr) {
                // If migration fails for any reason, keep using the legacy folder
                // so existing chats remain accessible.
                return $legacyFolder;
            }
        } catch (NotFoundException $e2) {
            // Neither exists - create the collision-free namespace.
            return $chats->newFolder($ns);
        }
    }

    /**
     * Collision-free namespace derived from the exact user ID.
     */
    private function namespaceFor(string $userId): string {
        return substr(hash('sha256', $userId), 0, 40);
    }

    /**
     * The old lossy slug (kept only for backwards-compatible migration).
     */
    private function legacySlug(string $userId): string {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';
    }

    /** Build a short excerpt around the first case-insensitive match of $needle. */
    private function snippetAround(string $text, string $needle): string {
        $pos = mb_stripos($text, $needle);
        if ($pos === false) {
            return mb_substr($text, 0, self::SNIPPET_RADIUS * 2 + 20);
        }
        $start = max(0, $pos - self::SNIPPET_RADIUS);
        $length = min(mb_strlen($text), self::SNIPPET_RADIUS * 2 + mb_strlen($needle));
        $snippet = mb_substr($text, $start, $length);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $length) < mb_strlen($text) ? '…' : '';
        $snippet = preg_replace('/\s+/u', ' ', $prefix . $snippet . $suffix) ?? $snippet;
        return trim($snippet);
    }

    private function clipTitle(string $title): string {
        $clean = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        if (mb_strlen($clean) > self::MAX_TITLE) {
            $clean = mb_substr($clean, 0, self::MAX_TITLE) . '…';
        }
        return $clean === '' ? 'Neuer Chat' : $clean;
    }
}
