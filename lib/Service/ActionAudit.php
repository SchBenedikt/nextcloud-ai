<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Privacy-conscious action audit trail (Issue #150).
 *
 * Every executed, rejected or failed mutating tool call gets a durable,
 * per-user event: timestamp, surface, tool, sanitized parameters, outcome and
 * a short human-readable detail. Secrets are redacted before persistence:
 * passwords, tokens, share passwords, message bodies and long content are
 * never stored. The log is a bounded JSON file per user in AppData, so users
 * (and admins via the aggregate endpoint) can see what was proposed and done
 * without leaking file content across users.
 *
 * Concurrency: writes are serialized through Nextcloud's shared locking
 * provider, exactly like ChatStore, so two web/app-server nodes cannot race on
 * the same user's file.
 */
class ActionAudit {
    private const FILE = 'actions.json';
    private const MAX_ENTRIES = 500;

    /** Argument keys whose values are never persisted (case-insensitive). */
    private const REDACT_KEYS = [
        'password', 'share_password', 'token', 'secret', 'apikey', 'api_key',
        'authorization', 'credentials', 'passphrase', 'body', 'message', 'content',
    ];

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private ILockingProvider $lockingProvider,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Append one event. Never throws: a failing audit write must not break the
     * tool call that produced it.
     *
     * @param array<string,mixed> $args raw tool arguments (redacted before store)
     */
    public function record(string $userId, string $surface, string $tool, array $args, string $outcome, string $detail): void {
        try {
            $entry = [
                'id' => 'a' . date('YmdHis') . '-' . bin2hex(random_bytes(4)),
                'ts' => time(),
                'surface' => $surface,
                'tool' => $tool,
                'args' => $this->sanitizeArgs($args),
                'outcome' => $outcome,
                'detail' => mb_substr($detail, 0, 300),
            ];
            $file = $this->rootFor($userId);
            $this->withUserLock($userId, function () use ($file, $entry): void {
                $entries = $this->readFile($file);
                array_unshift($entries, $entry);
                // Retention: bounded log, oldest entries are trimmed.
                if (count($entries) > self::MAX_ENTRIES) {
                    $entries = array_slice($entries, 0, self::MAX_ENTRIES);
                }
                $file->putContent(json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            });
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: audit write failed', ['user' => $userId, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * @return list<array{id:string,ts:int,surface:string,tool:string,args:array,outcome:string,detail:string}>
     */
    public function list(string $userId, int $limit = 100): array {
        try {
            $file = $this->rootFor($userId);
            $entries = $this->readFile($file);
            return array_slice($entries, 0, max(1, min(500, $limit)));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function clear(string $userId): int {
        try {
            $file = $this->rootFor($userId);
            return $this->withUserLock($userId, function () use ($file): int {
                $count = count($this->readFile($file));
                $file->putContent('[]');
                return $count;
            });
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Remove every trace for a deleted account (Issue #83). */
    public function deleteUserData(string $userId): void {
        try {
            $appdata = $this->appData();
            try {
                $audit = $appdata->getFolder('audit');
            } catch (NotFoundException $e) {
                return;
            }
            $ns = substr(hash('sha256', $userId), 0, 40);
            try {
                $audit->getFolder($ns)->delete();
            } catch (NotFoundException $e) {
                // Nothing stored for this user.
            }
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: audit cleanup failed', ['user' => $userId]);
        }
    }

    /**
     * Sanitize tool arguments for durable storage: sensitive values are
     * dropped entirely, everything else is truncated and bounded.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function sanitizeArgs(array $args): array {
        $out = [];
        foreach ($args as $key => $value) {
            $k = strtolower((string)$key);
            if ($this->isSensitiveKey($k)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->sanitizeArgs($value);
                continue;
            }
            if (is_scalar($value)) {
                $out[$key] = mb_substr((string)$value, 0, 200);
            }
        }
        return $out;
    }

    private function isSensitiveKey(string $key): bool {
        foreach (self::REDACT_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function readFile(ISimpleFile $file): array {
        try {
            $raw = $file->getContent();
        } catch (NotFoundException $e) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function rootFor(string $user): ISimpleFile {
        $appdata = $this->appData();
        try {
            $audit = $appdata->getFolder('audit');
        } catch (NotFoundException $e) {
            $audit = $appdata->newFolder('audit');
        }
        $ns = substr(hash('sha256', $user), 0, 40);
        try {
            $userFolder = $audit->getFolder($ns);
        } catch (NotFoundException $e) {
            $userFolder = $audit->newFolder($ns);
        }
        if (!$userFolder->fileExists(self::FILE)) {
            $userFolder->newFile(self::FILE, '[]');
        }
        return $userFolder->getFile(self::FILE);
    }

    private function appData(): IAppData {
        return $this->appDataFactory->get('eva_ai');
    }

    private function withUserLock(string $user, callable $operation): mixed {
        $lockPath = 'eva_ai/audit/' . substr(hash('sha256', $user), 0, 40);
        try {
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: audit lock could not be acquired', ['user' => $user]);
            throw new \RuntimeException('Unable to acquire the EVA audit lock');
        }
        try {
            return $operation();
        } finally {
            try {
                $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Throwable $e) {
                // Already released (e.g. expired TTL) - do not mask the result.
            }
        }
    }

    /** @return list<string> users with audit data (metadata only, for the admin aggregate) */
    public function usersWithData(): array {
        try {
            $appdata = $this->appData();
            try {
                $audit = $appdata->getFolder('audit');
            } catch (NotFoundException $e) {
                return [];
            }
            $names = [];
            foreach ($audit->getDirectoryListing() as $folder) {
                if ($folder instanceof ISimpleFolder && $folder->fileExists(self::FILE)) {
                    $names[] = $folder->getName();
                }
            }
            return $names;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
