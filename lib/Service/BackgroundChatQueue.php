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
            foreach ($items as $item) {
                if (($item['chatId'] ?? '') === $chatId && ($item['message'] ?? '') === $message && in_array(($item['status'] ?? ''), ['pending', 'running'], true)) return (string)$item['id'];
            }
            if (count($items) >= self::MAX_ITEMS) return null;
            $id = is_string($requestedId) && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $requestedId) === 1
                ? $requestedId : 'bg_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
            $items[] = ['id' => $id, 'chatId' => $chatId, 'message' => $message, 'history' => $cleanHistory, 'status' => 'pending', 'attempts' => 0, 'created' => time(), 'availableAt' => time() + 15];
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
                if (($status === 'pending' || $stale) && (int)($item['availableAt'] ?? 0) <= $now) {
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
    public function retry(string $user, string $id, string $error): void {
        $this->mutate($user, function (array $items) use ($id, $error): array {
            foreach ($items as &$item) if (($item['id'] ?? '') === $id) { $attempts = (int)($item['attempts'] ?? 1); if ($attempts >= 3) { $item['status'] = 'failed'; $item['error'] = mb_substr($error, 0, 500); } else { $item['status'] = 'pending'; $item['availableAt'] = time() + min(300, 30 * $attempts); } }
            return array_values(array_filter($items, static fn(array $i): bool => ($i['status'] ?? '') !== 'failed'));
        });
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
