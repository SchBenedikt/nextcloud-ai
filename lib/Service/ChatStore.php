<?php

declare(strict_types=1);

namespace OCA\RagChat\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Persistiert Chats pro Benutzer als JSON im AppData-Verzeichnis.
 * Jeder Chat: {id, title, created, updated, messages:[{role,text}]}
 */
class ChatStore {
    private const MAX_MESSAGES = 200;
    private const MAX_TITLE = 60;

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private LoggerInterface $logger
    ) {
    }

    /** @return list<array{id:string,title:string,created:int,updated:int,count:int}> */
    public function list(string $user): array {
        $all = $this->read($user);
        $out = [];
        foreach ($all as $chat) {
            $out[] = [
                'id' => $chat['id'] ?? '',
                'title' => $chat['title'] ?? 'Neuer Chat',
                'created' => $chat['created'] ?? 0,
                'updated' => $chat['updated'] ?? 0,
                'count' => count($chat['messages'] ?? []),
            ];
        }
        usort($out, static fn($a, $b) => $b['updated'] <=> $a['updated']);
        return $out;
    }

    /** @return array|null */
    public function get(string $user, string $id): ?array {
        foreach ($this->read($user) as $chat) {
            if (($chat['id'] ?? '') === $id) {
                return $chat;
            }
        }
        return null;
    }

    public function create(string $user, ?string $title = null): array {
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
    }

    public function delete(string $user, string $id): bool {
        $all = $this->read($user);
        $kept = array_values(array_filter($all, static fn($c) => ($c['id'] ?? '') !== $id));
        if (count($kept) === count($all)) {
            return false;
        }
        $this->write($user, $kept);
        return true;
    }

    public function setTitle(string $user, string $id, string $title): void {
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
    }

    public function append(string $user, string $id, string $role, string $text): void {
        if ($role !== 'user' && $role !== 'assistant') {
            return;
        }
        $all = $this->read($user);
        foreach ($all as &$chat) {
            if (($chat['id'] ?? '') === $id) {
                $chat['messages'][] = ['role' => $role, 'text' => $text];
                $chat['updated'] = time();
                if (count($chat['messages']) > self::MAX_MESSAGES) {
                    $chat['messages'] = array_slice($chat['messages'], -self::MAX_MESSAGES);
                }
                if (isset($chat['messages'][0]['text']) && str_starts_with($chat['title'] ?? '', 'Neuer Chat')) {
                    $chat['title'] = $this->clipTitle((string)$chat['messages'][0]['text']);
                }
                break;
            }
        }
        unset($chat);
        $this->write($user, $all);
    }

    /** @return list<array{id:string,title:string,created:int,updated:int,messages:list<array{role:string,text:string}>}> */
    private function read(string $user): array {
        try {
            $raw = $this->rootFor($user)->getContent();
        } catch (NotFoundException $e) {
            return [];
        } catch (NotPermittedException $e) {
            $this->logger->warning('ragchat: chat folder not readable (permissions?)', ['user' => $user]);
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function write(string $user, array $data): void {
        try {
            $this->rootFor($user)->putContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->logger->error('ragchat: chat save failed - chats may disappear after reload', [
                'user' => $user,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function rootFor(string $user): \OCP\Files\SimpleFS\ISimpleFile {
        $appdata = $this->appDataFactory->get('ragchat');
        try {
            $chats = $appdata->getFolder('chats');
        } catch (NotFoundException $e) {
            $chats = $appdata->newFolder('chats');
        }
        $slug = $this->slug($user);
        try {
            $uidFolder = $chats->getFolder($slug);
        } catch (NotFoundException $e) {
            $uidFolder = $chats->newFolder($slug);
        }
        if (!$uidFolder->fileExists('chats.json')) {
            $uidFolder->newFile('chats.json', '[]');
        }
        return $uidFolder->getFile('chats.json');
    }

    private function slug(string $userId): string {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';
    }

    private function clipTitle(string $title): string {
        $clean = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        if (mb_strlen($clean) > self::MAX_TITLE) {
            $clean = mb_substr($clean, 0, self::MAX_TITLE) . '…';
        }
        return $clean === '' ? 'Neuer Chat' : $clean;
    }
}