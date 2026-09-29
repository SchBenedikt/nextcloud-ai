<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Executes Talk tools using only rooms the effective user can access.
 */
final class TalkToolExecutor implements DomainToolExecutor {
    private const TOOLS = ['list_talk_rooms', 'read_talk_chat', 'send_talk_message'];

    public function __construct(private TalkChatService $talkChat) {
    }

    public function tools(): array {
        return self::TOOLS;
    }

    public function execute(string $tool, string $userId, array $args): array {
        return match ($tool) {
            'list_talk_rooms' => $this->listTalkRooms($userId, $args),
            'read_talk_chat' => $this->readTalkChat($userId, $args),
            'send_talk_message' => $this->sendTalkMessage($userId, $args),
            default => ['ok' => false, 'error' => 'Unsupported Talk tool: ' . $tool],
        };
    }

    private function listTalkRooms(string $userId, array $args): array
    {
        $limit = (int)($args['limit'] ?? 25);
        $rooms = $this->talkChat->rooms($userId, $limit);
        if ($rooms === []) {
            return [
                'ok' => false,
                'error' => 'No Nextcloud Talk rooms found. Either Talk is not installed, or the user is not a member of any room.',
            ];
        }
        return ['ok' => true, 'result' => $rooms];
    }

    /**
     * Read one Talk room's recent messages. The room is resolved against the
     * user's own room list, so a name from the model can never reach a room the
     * user is not in.
     */
    private function readTalkChat(string $userId, array $args): array
    {
        $room = trim((string)($args['room'] ?? ''));
        if ($room === '') {
            return ['ok' => false, 'error' => 'room required'];
        }
        $limit = (int)($args['limit'] ?? 50);
        $result = $this->talkChat->read($userId, $room, $limit, !empty($args['unread_only']));
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'The chat could not be read.')];
        }
        return [
            'ok' => true,
            'result' => [
                'room' => $result['room'],
                'messages' => $result['messages'],
                'unreadOnly' => (bool)($result['unreadOnly'] ?? false),
                'lastReadMessage' => $result['lastReadMessage'] ?? null,
                'text' => $result['text'],
            ],
        ];
    }

    /**
     * Post into a Talk room as the asking user.
     */
    private function sendTalkMessage(string $userId, array $args): array
    {
        $room = trim((string)($args['room'] ?? ''));
        $message = trim((string)($args['message'] ?? ''));
        if ($room === '' || $message === '') {
            return ['ok' => false, 'error' => 'room and message required'];
        }
        $result = $this->talkChat->send($userId, $room, $message);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => (string)($result['error'] ?? 'The message could not be posted.')];
        }
        return [
            'ok' => true,
            'result' => [
                'room' => $result['room'],
                'messageId' => $result['messageId'],
                'sentAt' => $result['sentAt'],
            ],
        ];
    }
}
