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
     * List the user's chats, newest first. Archived chats are hidden from the
     * default list; pass $includeArchived to get them back (Issue #87).
     *
     * With $search the list is filtered to chats whose title or any message
     * contains the needle (case-insensitive); message hits add a short snippet
     * around the first match and a count of matching messages, so the chat list
     * can find content, not only titles (Issue #152).
     *
     * @return list<array{id:string,title:string,created:int,updated:int,count:int,pinned:bool,folder:string,archived:bool,snippet?:string,matchCount?:int,trimmed?:int}>
     */
    public function list(string $user, ?string $search = null, bool $includeArchived = false): array {
        return $this->withUserLock($user, function () use ($user, $search, $includeArchived): array {
            $all = $this->read($user);
            $needle = $search !== null ? mb_strtolower(trim($search)) : '';
            $out = [];
            foreach ($all as $chat) {
                if (!$includeArchived && !empty($chat['archived'])) {
                    continue;
                }
                $messages = $chat['messages'] ?? [];
                $entry = [
                    'id' => $chat['id'] ?? '',
                    // Legacy chats store a hardcoded German default title; it
                    // is normalized away here so each client can render its
                    // own translated "New chat" placeholder instead.
                    'title' => $this->displayTitle($chat),
                    'created' => $chat['created'] ?? 0,
                    'updated' => $chat['updated'] ?? 0,
                    'count' => count($messages),
                    'trimmed' => (int)($chat['trimmed'] ?? 0),
                    // Organisational metadata (Issue #87); missing on legacy
                    // chats and defaulted so old data keeps working unchanged.
                    'pinned' => !empty($chat['pinned']),
                    'folder' => (string)($chat['folder'] ?? ''),
                    'archived' => !empty($chat['archived']),
                    // Per-chat RAG folder scope (Issue #88); empty = global.
                    'scopePath' => (string)($chat['scopePath'] ?? ''),
                    // Per-chat custom instructions (Issue #90): a free-text
                    // system-prompt override plus an optional preset persona.
                    'instructions' => (string)($chat['instructions'] ?? ''),
                    'persona' => (string)($chat['persona'] ?? ''),
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
            // Pinned chats first, then by most recently updated.
            usort($out, static fn($a, $b) => ($b['pinned'] <=> $a['pinned']) ?: ($b['updated'] <=> $a['updated']));
            return $out;
        });
    }

    /** @return array|null */
    public function get(string $user, string $id): ?array {
        return $this->withUserLock($user, function () use ($user, $id): ?array {
            foreach ($this->read($user) as $chat) {
                if (($chat['id'] ?? '') === $id) {
                    // Same normalization as list(): the stored German default
                    // title is a display detail, not stored truth.
                    $chat['title'] = $this->displayTitle($chat);
                    // The pending-regeneration marker is internal bookkeeping
                    // and never exposed to clients (Issue #182).
                    unset($chat['regenerate']);
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
                if (count($existing['messages'] ?? []) === 0
                    && empty($existing['archived']) && empty($existing['instructions'])
                    && empty($existing['scopePath']) && empty($existing['folder'])
                    && in_array($existing['persona'] ?? '', ['', 'default'], true)
                    && ($title === null || $title === '' || ($existing['title'] ?? '') === $this->clipTitle($title))) {
                    $existing['reused'] = true;
                    $existing['title'] = $this->displayTitle($existing);
                    return $existing;
                }
            }
            $chat = [
                'id' => 'c' . date('YmdHis') . '-' . bin2hex(random_bytes(4)),
                'title' => $title !== null && $title !== '' ? $this->clipTitle($title) : '',
                'created' => time(),
                'updated' => time(),
                'messages' => [],
                // Monotonic per-chat revision: every mutation bumps it, and
                // regenerate/edit requests validate against it so concurrent
                // edits cannot silently overwrite each other (Issue #182).
                'rev' => 1,
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
     * Delete every chat whose last activity (updated) is older than $days
     * days (Issue: chat retention). Chats are matched on their update
     * timestamp so actively used conversations are never touched. Returns the
     * number of removed chats; 0 when there is nothing to delete. A value of
     * 0 or less disables the cleanup entirely.
     */
    public function deleteOlderThan(string $user, int $days): int {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = time() - $days * 86400;
        return $this->withUserLock($user, function () use ($user, $cutoff): int {
            $all = $this->read($user);
            if ($all === []) {
                return 0;
            }
            // Chats without a usable timestamp are never deleted: their
            // activity is unknown, so retention must not remove them.
            $kept = array_values(array_filter($all, static fn($c) => (int)($c['updated'] ?? 0) <= 0 || (int)$c['updated'] > $cutoff));
            $deleted = count($all) - count($kept);
            if ($deleted > 0) {
                $this->write($user, $kept);
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
                    $chat['rev'] = (int)($chat['rev'] ?? 0) + 1;
                    break;
                }
            }
            unset($chat);
            $this->write($user, $all);
        });
    }

    /**
     * Update organisational chat metadata (Issue #87): pinned, folder and
     * archived. Only the keys present in $meta are touched, legacy chats
     * without the fields keep working. Assigning a chat to a folder that does
     * not exist yet creates that folder on the fly, so the UI can offer
     * "type a new folder name" without a separate round-trip. Returns false
     * only when the chat itself does not exist.
     */
    public function setMeta(string $user, string $id, array $meta): bool {
        return $this->withUserLock($user, function () use ($user, $id, $meta): bool {
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') !== $id) {
                    continue;
                }
                if (array_key_exists('pinned', $meta)) {
                    $chat['pinned'] = !empty($meta['pinned']);
                }
                if (array_key_exists('archived', $meta)) {
                    $chat['archived'] = !empty($meta['archived']);
                }
                if (array_key_exists('folder', $meta)) {
                    $folder = trim((string)$meta['folder']);
                    if ($folder !== '') {
                        // Unknown names create the folder (Issue #87) so the
                        // sidebar's "new folder" flow needs no extra API call.
                        $this->createFolderLocked($user, $folder);
                    }
                    $chat['folder'] = $folder;
                }
                if (array_key_exists('scopePath', $meta)) {
                    // Per-chat retrieval scope (Issue #88): restricts RAG to
                    // documents at/under this folder path. Empty clears it.
                    $chat['scopePath'] = trim((string)$meta['scopePath']);
                }
                if (array_key_exists('instructions', $meta)) {
                    // Per-chat custom instructions (Issue #90). Capped to the
                    // same limit RagService enforces when building prompts.
                    $chat['instructions'] = mb_substr(trim((string)$meta['instructions']), 0, 2000);
                }
                if (array_key_exists('persona', $meta)) {
                    // Preset persona slug (Issue #90); validated against the
                    // known set in RagService, stored verbatim here.
                    $chat['persona'] = trim((string)$meta['persona']);
                }
                $chat['updated'] = time();
                $chat['rev'] = (int)($chat['rev'] ?? 0) + 1;
                unset($chat);
                $this->write($user, $all);
                return true;
            }
            unset($chat);
            return false;
        });
    }

    /**
     * Create a folder. Names are trimmed, capped and de-duplicated; a folder
     * that already exists is returned unchanged (idempotent).
     */
    public function createFolder(string $user, string $name): array {
        return $this->withUserLock($user, function () use ($user, $name): array {
            return $this->createFolderLocked($user, $name);
        });
    }

    /**
     * Create a folder while the per-user lock is already held. Names are
     * trimmed, capped and de-duplicated; an existing folder is returned
     * unchanged (idempotent). Callers must hold the user lock.
     */
    private function createFolderLocked(string $user, string $name): array {
        $folders = $this->foldersLocked($user);
        $clean = $this->clipFolderName($name);
        foreach ($folders as $folder) {
            if (($folder['name'] ?? '') === $clean) {
                return $folder;
            }
        }
        $folder = ['name' => $clean, 'created' => time()];
        $folders[] = $folder;
        $this->writeFoldersLocked($user, $folders);
        return $folder;
    }

    /**
     * Rename a folder (and every chat assigned to it). Returns false when the
     * source folder does not exist or the new name is already taken.
     */
    public function renameFolder(string $user, string $from, string $to): bool {
        return $this->withUserLock($user, function () use ($user, $from, $to): bool {
            $folders = $this->foldersLocked($user);
            $clean = $this->clipFolderName($to);
            $found = false;
            foreach ($folders as &$folder) {
                if (($folder['name'] ?? '') === $from) {
                    if (in_array($clean, array_column($folders, 'name'), true)) {
                        return false;
                    }
                    $folder['name'] = $clean;
                    $found = true;
                    break;
                }
            }
            unset($folder);
            if (!$found) {
                return false;
            }
            $this->writeFoldersLocked($user, $folders);
            // Re-point chats that were assigned to the old folder name.
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['folder'] ?? '') === $from) {
                    $chat['folder'] = $clean;
                }
            }
            unset($chat);
            $this->write($user, $all);
            return true;
        });
    }

    /**
     * Delete a folder and unassign every chat that referenced it.
     * Returns false when the folder does not exist.
     */
    public function deleteFolder(string $user, string $name): bool {
        return $this->withUserLock($user, function () use ($user, $name): bool {
            $folders = $this->foldersLocked($user);
            $kept = array_values(array_filter($folders, static fn($f) => ($f['name'] ?? '') !== $name));
            if (count($kept) === count($folders)) {
                return false;
            }
            $this->writeFoldersLocked($user, $kept);
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['folder'] ?? '') === $name) {
                    $chat['folder'] = '';
                }
            }
            unset($chat);
            $this->write($user, $all);
            return true;
        });
    }

    /**
     * All folders of the user, sorted by name.
     *
     * @return list<array{name:string,created:int}>
     */
    public function listFolders(string $user): array {
        return $this->withUserLock($user, function () use ($user): array {
            $folders = $this->foldersLocked($user);
            usort($folders, static fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
            return $folders;
        });
    }

    /** Folder names already sorted (used inside the user lock). */
    private function folderNamesLocked(string $user): array {
        return array_values(array_map(
            static fn($f) => (string)($f['name'] ?? ''),
            $this->foldersLocked($user)
        ));
    }

    /**
     * The folder registry lives next to chats.json so the two can be read and
     * written atomically under the same per-user lock (Issue #87).
     *
     * @return list<array{name:string,created:int}>
     */
    private function foldersLocked(string $user): array {
        try {
            $raw = $this->foldersFile($user)->getContent();
        } catch (NotFoundException $e) {
            return [];
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: folder registry unreadable', ['exception' => $e->getMessage()]);
            throw $e;
        }
        return $this->decodeStoredList($raw, 'folder registry');
    }

    private function writeFoldersLocked(string $user, array $folders): void {
        $this->foldersFile($user)->putContent(
            json_encode($folders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function foldersFile(string $user): \OCP\Files\SimpleFS\ISimpleFile {
        $uidFolder = $this->userFolderFor($user);
        if (!$uidFolder->fileExists('folders.json')) {
            $uidFolder->newFile('folders.json', '[]');
        }
        return $uidFolder->getFile('folders.json');
    }

    private function clipFolderName(string $name): string {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (mb_strlen($clean) > 60) {
            $clean = mb_substr($clean, 0, 60) . '…';
        }
        return $clean === '' ? 'Unbenannt' : $clean;
    }

    /**
     * The user's chat namespace folder (shared by chats.json and folders.json).
     */
    private function userFolderFor(string $user): \OCP\Files\SimpleFS\ISimpleFolder {
        $appdata = $this->appDataFactory->get('eva_ai');
        try {
            $chats = $appdata->getFolder('chats');
        } catch (NotFoundException $e) {
            $chats = $appdata->newFolder('chats');
        }
        $this->copyLegacyChatData($chats);
        return $this->folderFor($chats, $user);
    }

    /**
     * Append (or replace) a chat message.
     *
     * A pending tool confirmation (Issue #185) is persisted on the assistant
     * message so a page reload can rebuild the inline confirmation panel.
     * Saving another assistant message whose predecessor is an unresolved
     * pending confirmation replaces that placeholder instead of appending a
     * duplicate — that is how the confirmed answer is persisted.
     */
    public function append(string $user, string $id, string $role, string $text, array $followups = [], ?int $regenerateRev = null, ?array $confirmation = null): void {
        if ($role !== 'user' && $role !== 'assistant') {
            return;
        }
        $this->withUserLock($user, function () use ($user, $id, $role, $text, $followups, $regenerateRev, $confirmation): void {
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') === $id) {
                    $message = ['role' => $role, 'text' => $text];
                    // Follow-up suggestions belong to assistant messages and
                    // must survive a reload so the chips stay usable.
                    if ($role === 'assistant' && $followups !== []) {
                        $message['followups'] = array_values(array_slice(array_filter($followups, 'is_string'), 0, 3));
                    }
                    if ($role === 'assistant' && is_array($confirmation)) {
                        $message['confirmation'] = $this->normalizeConfirmation($confirmation);
                    }
                    $pending = $chat['regenerate'] ?? null;
                    if ($regenerateRev !== null && is_array($pending)
                        && (int)($pending['rev'] ?? -1) === $regenerateRev) {
                        // Regeneration succeeded: commit the deferred
                        // truncation and the optional user-text edit atomically
                        // with the new answer. A failed model call never
                        // touched the stored history (Issue #182).
                        $pendingIndex = (int)($pending['messageIndex'] ?? -1);
                        if ($pendingIndex >= 0 && $pendingIndex < count($chat['messages'])) {
                            if (($pending['newText'] ?? null) !== null) {
                                $chat['messages'][$pendingIndex]['text'] = (string)$pending['newText'];
                            }
                            $chat['messages'] = array_slice($chat['messages'], 0, $pendingIndex + 1);
                        }
                        unset($chat['regenerate']);
                    } elseif (is_array($pending)) {
                        // A plain append supersedes a pending regeneration: the
                        // stored messages were never truncated, so the marker is
                        // dropped and a late regenerate answer can no longer
                        // truncate this newer message.
                        unset($chat['regenerate']);
                    }
                    // An unresolved pending confirmation placeholder is replaced
                    // by the confirmed answer instead of being duplicated, so
                    // approving after a reload persists exactly one answer
                    // (Issue #185).
                    $replaceIndex = -1;
                    if ($role === 'assistant') {
                        for ($i = count($chat['messages']) - 1; $i >= 0; $i--) {
                            $stored = $chat['messages'][$i];
                            if (($stored['role'] ?? '') === 'assistant'
                                && isset($stored['confirmation'])
                                && empty($stored['confirmation']['resolved'])) {
                                $replaceIndex = $i;
                                break;
                            }
                        }
                    }
                    if ($replaceIndex >= 0) {
                        $chat['messages'][$replaceIndex] = $message;
                    } else {
                        $chat['messages'][] = $message;
                    }
                    $chat['updated'] = time();
                    $chat['rev'] = (int)($chat['rev'] ?? 0) + 1;
                    $messageCount = count($chat['messages']);
                    if ($messageCount > self::MAX_MESSAGES) {
                        $dropped = $messageCount - self::MAX_MESSAGES;
                        $chat['messages'] = array_slice($chat['messages'], -self::MAX_MESSAGES);
                        // Keep a durable counter so truncation is never silent.
                        $chat['trimmed'] = (int)($chat['trimmed'] ?? 0) + $dropped;
                    }
                    $storedTitle = $chat['title'] ?? '';
                    if (isset($chat['messages'][0]['text'])
                        && ($storedTitle === '' || str_starts_with($storedTitle, 'Neuer Chat'))) {
                        $chat['title'] = $this->clipTitle((string)$chat['messages'][0]['text']);
                    }
                    break;
                }
            }
            unset($chat);
            $this->write($user, $all);
        });
    }

    /**
     * Truncate a chat's messages after the given 0-based index (inclusive).
     * Messages at and after $fromIndex are removed.
     */
    /**
     * Begin a regenerate or edit (Issue #182): validate the target user
     * message and record a pending regeneration token. The chat is NOT
     * truncated here — the truncation and optional user-text edit are
     * committed atomically by append() once the new assistant answer is
     * persisted, so a failed model call leaves the stored history intact.
     *
     * $expectedRev is the revision the client loaded; when it does not match
     * the stored revision the chat was modified elsewhere and the request is
     * rejected (conflict). A pending regeneration from the same base revision
     * (a retry after a failed stream) is allowed to replace the marker.
     *
     * @return array{ok:true,rev:int,messageIndex:int,targetText:string}|array{ok:false,error:string}
     */
    public function beginRegenerate(string $user, string $id, int $messageIndex, ?string $newText, ?int $expectedRev): array {
        return $this->withUserLock($user, function () use ($user, $id, $messageIndex, $newText, $expectedRev): array {
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') !== $id) {
                    continue;
                }
                $messages = $chat['messages'] ?? [];
                $currentRev = (int)($chat['rev'] ?? 0);
                $validTarget = $messageIndex >= 0 && $messageIndex < count($messages)
                    && ($messages[$messageIndex]['role'] ?? '') === 'user'
                    && ($newText === null || trim($newText) !== '')
                    && ($newText !== null || trim((string)($messages[$messageIndex]['text'] ?? '')) !== '');
                if (!$validTarget) {
                    return ['ok' => false, 'error' => 'invalid'];
                }
                $pending = $chat['regenerate'] ?? null;
                if (is_array($pending)) {
                    // A regeneration is already pending. The same client (same
                    // base revision) may retry after a failed stream; anything
                    // else means a concurrent edit and is rejected.
                    if ($expectedRev === null || (int)($pending['baseRev'] ?? -1) !== $expectedRev) {
                        return ['ok' => false, 'error' => 'conflict'];
                    }
                } elseif ($expectedRev !== null && $currentRev !== $expectedRev) {
                    return ['ok' => false, 'error' => 'conflict'];
                }
                $rev = $currentRev + 1;
                // On a retry the base revision stays the original one: the
                // retrying client still validates against the state it loaded,
                // not against the revision the failed attempt bumped.
                $baseRev = is_array($pending) ? (int)($pending['baseRev'] ?? $currentRev) : $currentRev;
                $chat['regenerate'] = [
                    'messageIndex' => $messageIndex,
                    'newText' => $newText,
                    'rev' => $rev,
                    'baseRev' => $baseRev,
                ];
                $chat['rev'] = $rev;
                $chat['updated'] = time();
                unset($chat);
                $this->write($user, $all);
                return [
                    'ok' => true,
                    'rev' => $rev,
                    'messageIndex' => $messageIndex,
                    'targetText' => $newText ?? (string)($messages[$messageIndex]['text'] ?? ''),
                ];
            }
            return ['ok' => false, 'error' => 'not_found'];
        });
    }

    public function truncateAfter(string $user, string $id, int $fromIndex): void {
        $this->withUserLock($user, function () use ($user, $id, $fromIndex): void {
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') === $id) {
                    if ($fromIndex >= 0 && $fromIndex < count($chat['messages'])) {
                        $chat['messages'] = array_slice($chat['messages'], 0, $fromIndex);
                        $chat['updated'] = time();
                        $chat['rev'] = (int)($chat['rev'] ?? 0) + 1;
                    }
                    break;
                }
            }
            unset($chat);
            $this->write($user, $all);
        });
    }

    /**
     * Replace the text of a message at the given 0-based index.
     */
    public function replaceMessage(string $user, string $id, int $index, string $newText): void {
        $this->withUserLock($user, function () use ($user, $id, $index, $newText): void {
            $all = $this->read($user);
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') === $id) {
                    if (isset($chat['messages'][$index])) {
                        $chat['messages'][$index]['text'] = $newText;
                        $chat['updated'] = time();
                        $chat['rev'] = (int)($chat['rev'] ?? 0) + 1;
                    }
                    break;
                }
            }
            unset($chat);
            $this->write($user, $all);
        });
    }

    /**
     * Get a specific chat by ID.
     *
     * @return array{id:string,title:string,created:int,updated:int,messages:list<array{role:string,text:string}>}|null
     */
    public function getChat(string $user, string $id): ?array {
        return $this->get($user, $id);
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
        return $this->decodeStoredList($raw, 'chat data');
    }

    private function decodeStoredList(string $raw, string $label): array {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid EVA ' . $label . '; stored data was preserved.', 0, $e);
        }
        if (!str_starts_with(ltrim($raw), '[') || !is_array($data) || !array_is_list($data)
            || count(array_filter($data, 'is_array')) !== count($data)) {
            throw new \RuntimeException('Invalid EVA ' . $label . '; stored data was preserved.');
        }
        return $data;
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
     * Admin recovery path for a corrupt chats.json (Issue #184). The corrupt
     * file is preserved (the #173 fail-safe) and never silently overwritten:
     * with $apply=false this only reports what a repair would do, with
     * $apply=true the damaged file is backed up first and a minimal valid
     * store (keeping every parseable chat) is written.
     *
     * @return array{status:string,backup:?string,kept:int,message:string}
     */
    public function repairStore(string $user, bool $apply = false): array {
        return $this->withUserLock($user, function () use ($user, $apply): array {
            $raw = $this->rootFor($user)->getContent();
            try {
                $this->decodeStoredList($raw, 'chat data');
                return ['status' => 'ok', 'backup' => null, 'kept' => count($this->read($user)), 'message' => 'Chat storage is valid, nothing to repair.'];
            } catch (\RuntimeException $e) {
                // Fall through: the stored data is corrupt and preserved.
            }
            $decoded = json_decode($raw, true);
            $kept = [];
            if (is_array($decoded)) {
                $candidates = array_is_list($decoded) ? $decoded : [$decoded];
                foreach ($candidates as $candidate) {
                    if (is_array($candidate) && is_string($candidate['id'] ?? null) && $candidate['id'] !== '') {
                        $kept[] = $candidate;
                    }
                }
            }
            if (!$apply) {
                return [
                    'status' => 'corrupt',
                    'backup' => null,
                    'kept' => count($kept),
                    'message' => 'Corrupt chat storage detected: would back up the file and reconstruct the store keeping ' . count($kept) . ' parseable chat(s). Re-run with --yes to apply.',
                ];
            }
            $backupName = 'chats.json.corrupt-' . date('Ymd-His');
            $folder = $this->userFolderFor($user);
            if (!$folder->fileExists($backupName)) {
                $folder->newFile($backupName, $raw);
            }
            $this->rootFor($user)->putContent(json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return [
                'status' => 'repaired',
                'backup' => $backupName,
                'kept' => count($kept),
                'message' => 'Repaired chat storage: backup written as ' . $backupName . ', store reconstructed keeping ' . count($kept) . ' parseable chat(s).',
            ];
        });
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
        $uidFolder = $this->userFolderFor($user);
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
        return $clean;
    }

    /**
     * The title a client should display. Untitled chats (and legacy chats
     * with the old hardcoded German default) map to an empty string so each
     * frontend can show its own translated placeholder.
     */
    private function displayTitle(array $chat): string {
        $title = (string)($chat['title'] ?? '');
        return $title === 'Neuer Chat' ? '' : $title;
    }

    /**
     * Normalize a client-supplied confirmation payload (Issue #185) so only
     * known, typed fields are persisted on a chat message. Resolved answers
     * keep just the fields the UI needs to render the result link — the raw
     * tool arguments (which may contain passwords or paths) are dropped once
     * the action has run.
     */
    private function normalizeConfirmation(array $raw): array {
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        if ($name === '') {
            return ['resolved' => true];
        }
        $risk = (string)($raw['risk'] ?? 'mutating');
        $risk = in_array($risk, ['mutating', 'destructive'], true) ? $risk : 'mutating';
        $confirmation = [
            'name' => $name,
            'risk' => $risk,
            'resolved' => !empty($raw['resolved']),
        ];
        // The idempotency token (Issue #185) survives resolution so a second
        // confirmTool call for the same action can be rejected.
        $token = is_string($raw['token'] ?? null) ? trim($raw['token']) : '';
        if ($token !== '') {
            $confirmation['token'] = $token;
        }
        if (!empty($confirmation['resolved'])) {
            $url = (string)($raw['resultUrl'] ?? '');
            if ($url !== '') {
                $confirmation['resultUrl'] = $url;
            }
            return $confirmation;
        }
        $confirmation['arguments'] = is_array($raw['arguments'] ?? null) ? $raw['arguments'] : [];
        $missing = $raw['missing'] ?? [];
        $confirmation['missing'] = is_array($missing)
            ? array_values(array_map('strval', array_slice($missing, 0, 10)))
            : [];
        return $confirmation;
    }

    /**
     * Idempotency guard for tool confirmations (Issue #185): claim a pending
     * confirmation token before executing its action so a second approve (e.g.
     * after a reload) cannot run a mutating tool twice.
     *
     * @return string 'ok' when claimed, 'already' when the token is claimed or
     *                resolved, 'none' when no pending confirmation carries it
     */
    public function claimConfirmation(string $user, string $id, string $token): string {
        if ($token === '') {
            return 'none';
        }
        return $this->withUserLock($user, function () use ($user, $id, $token): string {
            $all = $this->read($user);
            $changed = false;
            $status = 'none';
            foreach ($all as &$chat) {
                if (($chat['id'] ?? '') !== $id) {
                    continue;
                }
                for ($i = count($chat['messages']) - 1; $i >= 0; $i--) {
                    $conf = $chat['messages'][$i]['confirmation'] ?? null;
                    if (!is_array($conf) || ($conf['token'] ?? '') !== $token) {
                        continue;
                    }
                    if (!empty($conf['resolved']) || !empty($conf['claim'])) {
                        $status = 'already';
                    } else {
                        $chat['messages'][$i]['confirmation']['claim'] = true;
                        $status = 'ok';
                        $changed = true;
                    }
                    break;
                }
                break;
            }
            unset($chat);
            if ($changed) {
                $this->write($user, $all);
            }
            return $status;
        });
    }
}
