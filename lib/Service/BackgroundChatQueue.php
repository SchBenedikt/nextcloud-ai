<?php
declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/** Durable, per-user queue for chat requests that outlive a browser tab. */
final class BackgroundChatQueue {
    public const KEY = 'background_chat_queue';
    private const MAX_ITEMS = 10;
    private const MAX_MESSAGE_CHARS = 20000;
    private const MAX_HISTORY_ITEMS = 100;
    private const MAX_RUNTIME_SECONDS = 300;

    public function __construct(private IConfig $config, private ILockingProvider $locks, private LoggerInterface $logger) {}

    public function enqueue(string $user, string $chatId, string $message, array $history, ?string $requestedId = null): ?string {
        $message = trim($message);
        if ($user === '' || $chatId === '' || $message === '' || mb_strlen($message) > self::MAX_MESSAGE_CHARS) return null;
        $cleanHistory = [];
        foreach (array_slice($history, -self::MAX_HISTORY_ITEMS) as $item) {
            if (!is_array($item)) continue;
            $role = (string)($item['role'] ?? '');
            $content = (string)($item['content'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true) || trim($content) === '') continue;
            $cleanHistory[] = ['role' => $role, 'content' => mb_substr($content, 0, 20000)];
        }
        return $this->withLock($user, function () use ($user, $chatId, $message, $cleanHistory): ?string {
            $items = $this->read($user);
            // Keep terminal failures long enough for the UI/API to show them,
            // but garbage-collect old records so they cannot fill the queue.
            $cutoff = time() - 86400;
            $items = array_values(array_filter($items, static fn(array $item): bool =>
                (($item['status'] ?? '') !== 'failed' || (int)($item['finishedAt'] ?? 0) > $cutoff)
                && (($item['status'] ?? '') !== 'cancelled' || (int)($item['finishedAt'] ?? 0) > $cutoff)));
            foreach ($items as $item) {
                if (($item['chatId'] ?? '') === $chatId && ($item['message'] ?? '') === $message && in_array(($item['status'] ?? ''), ['pending', 'running'], true)) return (string)$item['id'];
            }
            $active = count(array_filter($items, static fn(array $item): bool => in_array(($item['status'] ?? ''), ['pending', 'running'], true)));
            if ($active >= self::MAX_ITEMS) return null;
            $id = is_string($requestedId) && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $requestedId) === 1
                ? $requestedId : 'bg_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
            $items[] = ['id' => $id, 'chatId' => $chatId, 'message' => $message, 'history' => $cleanHistory, 'status' => 'pending', 'attempts' => 0, 'steps' => 0, 'created' => time(), 'deadline' => time() + self::MAX_RUNTIME_SECONDS, 'availableAt' => time() + 15];
            $this->write($user, $items);
            return $id;
        });
    }

    /** Claim one due item; stale running claims are recoverable after 10 min. */
    public function claim(string $user): ?array {
        return $this->withLock($user, function () use ($user): ?array {
            $items = $this->read($user); $now = time(); $changed = false;
            foreach ($items as &$item) {
                $status = (string)($item['status'] ?? 'pending');
                $stale = $status === 'running' && $now - (int)($item['claimedAt'] ?? 0) > 600;
                if (($status === 'pending' || $stale) && empty($item['cancelRequested']) && (int)($item['availableAt'] ?? 0) <= $now) {
                    $item['status'] = 'running'; $item['claimedAt'] = $now; $item['attempts'] = (int)($item['attempts'] ?? 0) + 1; $changed = true;
                    $claimed = $item; break;
                }
            }
            unset($item);
            if ($changed) $this->write($user, $items);
            return $claimed ?? null;
        });
    }

    public function complete(string $user, string $id): void { $this->mutate($user, static fn(array $items): array => array_values(array_filter($items, static fn(array $i): bool => ($i['id'] ?? '') !== $id))); }
    public function markTimedOut(string $user, string $id): void {
        $this->mutate($user, static function (array $items) use ($id): array {
            foreach ($items as &$item) if (($item['id'] ?? '') === $id && ($item['status'] ?? '') === 'running') { $item['status'] = 'failed'; $item['error'] = 'Background run exceeded its five-minute time limit.'; $item['finishedAt'] = time(); }
            unset($item); return $items;
        });
    }
    /** Return queue progress without exposing the stored conversation history. */
    public function status(string $user): array {
        return $this->withLock($user, function () use ($user): array {
            $out = [];
            foreach ($this->read($user) as $item) {
                $out[] = [
                    'id' => (string)($item['id'] ?? ''),
                    'chatId' => (string)($item['chatId'] ?? ''),
                    'status' => in_array(($item['status'] ?? ''), ['pending', 'running', 'failed', 'cancelled'], true) ? (string)$item['status'] : 'pending',
                    'attempts' => max(0, (int)($item['attempts'] ?? 0)),
                    'created' => max(0, (int)($item['created'] ?? 0)),
                    'claimedAt' => max(0, (int)($item['claimedAt'] ?? 0)),
                    'message' => mb_strimwidth((string)($item['message'] ?? ''), 0, 240, '…'),
                    'error' => mb_strimwidth((string)($item['error'] ?? ''), 0, 500, '…'),
                    'phase' => in_array(($item['phase'] ?? ''), ['queued', 'model', 'tool', 'finalizing'], true) ? (string)$item['phase'] : 'queued',
                    'tool' => mb_strimwidth((string)($item['tool'] ?? ''), 0, 100, '…'),
                    'updatedAt' => max(0, (int)($item['updatedAt'] ?? $item['claimedAt'] ?? $item['created'] ?? 0)),
                    'steps' => max(0, (int)($item['steps'] ?? 0)),
                    'deadline' => max(0, (int)($item['deadline'] ?? 0)),
                ];
            }
            return $out;
        }, ILockingProvider::LOCK_SHARED) ?? [];
    }

    /** Persist coarse-grained progress so the UI can explain what EVA is doing. */
    public function updateProgress(string $user, string $id, string $phase, ?string $tool = null): void {
        $phase = in_array($phase, ['queued', 'model', 'tool', 'finalizing'], true) ? $phase : 'model';
        $this->mutate($user, function (array $items) use ($id, $phase, $tool): array {
            foreach ($items as &$item) if (($item['id'] ?? '') === $id && ($item['status'] ?? '') === 'running') {
                $item['phase'] = $phase; $item['tool'] = $tool !== null ? mb_substr($tool, 0, 100) : ''; $item['updatedAt'] = time();
                if ($phase === 'tool') $item['steps'] = (int)($item['steps'] ?? 0) + 1;
            }
            unset($item); return $items;
        });
    }

    /** Request cancellation; running workers observe this flag between steps. */
    public function cancel(string $user, string $id): bool {
        return (bool)$this->withLock($user, function () use ($user, $id): bool {
            $items = $this->read($user); $changed = false; $found = false;
            foreach ($items as &$item) if (($item['id'] ?? '') === $id) {
                $found = true; $changed = true;
                if (($item['status'] ?? '') === 'running') $item['cancelRequested'] = true;
                else { $item['status'] = 'cancelled'; $item['finishedAt'] = time(); }
            }
            unset($item);
            if ($changed) $this->write($user, $items);
            return $found;
        }) ?? false;
    }

    /** Fast, lock-protected cancellation check used by the model/tool loop. */
    public function isCancellationRequested(string $user, string $id): bool {
        return (bool)$this->withLock($user, function () use ($user, $id): bool {
            foreach ($this->read($user) as $item) if (($item['id'] ?? '') === $id) return !empty($item['cancelRequested']) || ($item['status'] ?? '') === 'cancelled';
            return false;
        }, ILockingProvider::LOCK_SHARED);
    }
    /** Retry a failed run; returns true when the item became terminal. */
    public function retry(string $user, string $id, string $error): bool {
        return (bool)$this->withLock($user, function () use ($user, $id, $error): bool {
            $items = $this->read($user);
            $terminal = false;
            $found = false;
            foreach ($items as &$item) if (($item['id'] ?? '') === $id) {
                $found = true;
                $attempts = (int)($item['attempts'] ?? 1);
                if ($attempts >= 3) {
                    $item['status'] = 'failed';
                    $item['error'] = mb_substr($error, 0, 500);
                    $item['finishedAt'] = time();
                    $terminal = true;
                } else {
                    $item['status'] = 'pending';
                    $item['availableAt'] = time() + min(300, 30 * $attempts);
                }
            }
            unset($item);
            if ($found) $this->write($user, $items);
            return $terminal;
        }) ?? false;
    }
    public function users(): array { try { return array_values(array_unique(array_filter(array_map('strval', $this->config->getUsersForUserValue(AppConfig::APP, self::KEY))))); } catch (\Throwable) { return []; } }

    private function read(string $user): array { $raw = $this->config->getUserValue($user, AppConfig::APP, self::KEY, '[]'); $data = json_decode($raw, true); return is_array($data) ? array_values(array_filter($data, 'is_array')) : []; }
    private function write(string $user, array $items): void { if ($items === []) { $this->config->deleteUserValue($user, AppConfig::APP, self::KEY); return; } $this->config->setUserValue($user, AppConfig::APP, self::KEY, json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'); }
    private function mutate(string $user, callable $fn): void { $this->withLock($user, function () use ($user, $fn): void { $this->write($user, $fn($this->read($user))); }); }
    private function withLock(string $user, callable $fn): mixed {
        $path = 'eva_ai/bgchat/' . substr(hash('sha256', $user), 0, 40); $acquired = false;
        try { $this->locks->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE, 'EVA background chat'); $acquired = true; return $fn(); }
        catch (\Throwable $e) { $this->logger->debug('eva_ai: background chat queue busy', ['user' => $user, 'exception' => $e]); return null; }
        finally { if ($acquired) { try { $this->locks->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE); } catch (\Throwable) {} } }
    }
}
