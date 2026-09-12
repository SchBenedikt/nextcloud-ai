<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\App\IAppManager;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use Psr\Log\LoggerInterface;

/**
 * Reads Nextcloud Talk chat histories for indexing.
 *
 * The EVA Talk bot already has the last few messages of the room it is answering
 * in, which is enough to follow a live conversation but not to answer "what did
 * we decide about X last month?". This service turns a room's history into text
 * that can be indexed like a document, so older parts of the conversation stay
 * searchable.
 *
 * Talk is an optional dependency: none of its classes may be referenced at
 * construction time, or the whole app would stop booting on an instance without
 * it. Services are therefore resolved lazily and every entry point checks
 * availability first.
 */
class TalkTranscriptService
{
    /** Talk's comment object type that holds chat messages. */
    private const CHAT_OBJECT_TYPE = 'chat';

    /**
     * Messages are read in pages. Talk returns newest first, so page 0 is the
     * most recent part of the history and the oldest messages are dropped first
     * when a room has more history than the configured budget.
     */
    private const PAGE_SIZE = 200;

    public function __construct(
        private AppConfig $appConfig,
        private IAppManager $appManager,
        private ICommentsManager $commentsManager,
        private Searcher $searcher,
        private LoggerInterface $logger,
    ) {
    }

    /** Whether Talk is installed and enabled, so indexing can run at all. */
    public function isAvailable(): bool
    {
        try {
            return $this->appManager->isEnabledForAnyone('spreed')
                && class_exists(\OCA\Talk\Manager::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Rooms the user is a participant of.
     *
     * Membership comes from Talk itself rather than from a stored list, so a
     * room the user has left simply stops appearing and is reconciled away.
     *
     * @return list<array{id:int,token:string,name:string}>
     */
    public function roomsForUser(string $userId, int $limit = 20): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            $manager = \OCP\Server::get(\OCA\Talk\Manager::class);
            $rooms = $manager->getRoomsForUser($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: could not list Talk rooms: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rooms as $room) {
            try {
                $id = (int)$room->getId();
                if ($id <= 0) {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'token' => (string)$room->getToken(),
                    'name' => $this->roomLabel($room, $userId),
                ];
            } catch (\Throwable $e) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Whether the user is currently a participant of the room.
     *
     * The retrieval path must never rely on the index having been built for the
     * right people: this is the authorization check, and it is made against Talk
     * at read time.
     */
    public function isMember(string $userId, int $roomId): bool
    {
        if (!$this->isAvailable() || $roomId <= 0) {
            return false;
        }
        try {
            $participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
            $room = \OCP\Server::get(\OCA\Talk\Manager::class)->getRoomById($roomId);
            return in_array($userId, $participantService->getParticipantUserIds($room), true);
        } catch (\Throwable $e) {
            // Fail closed: without a confirmed membership the history stays out
            // of the answer.
            return false;
        }
    }

    /**
     * Older parts of one room's conversation that match a question.
     *
     * This is what makes an indexed history useful in a live chat: the bot
     * already sees the last few messages, so only the older passages that matter
     * for this question are retrieved, and only from the room the question was
     * asked in. Retrieval is scoped to the room's own document and guarded by a
     * membership check, so one room's history can never surface in another.
     *
     * @return list<string> passages, best first
     */
    public function recall(string $userId, int $roomId, string $question, int $topK = 4): array
    {
        $question = trim($question);
        if ($question === '' || $roomId <= 0 || !$this->isMember($userId, $roomId)) {
            return [];
        }
        try {
            $results = $this->searcher->search($userId, $question, $topK, 'talk://' . $roomId);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: Talk history recall failed: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($results as $result) {
            $content = trim((string)($result['content'] ?? ''));
            if ($content !== '') {
                $out[] = $content;
            }
        }
        return $out;
    }

    /**
     * The readable transcript of one room, oldest message first.
     *
     * @return array{roomId:int,name:string,text:string,messages:int}|null
     */
    public function transcript(string $userId, int $roomId, ?int $maxMessages = null): ?array
    {
        if (!$this->isMember($userId, $roomId)) {
            return null;
        }
        $roomName = '';
        try {
            $roomName = $this->roomLabel(
                \OCP\Server::get(\OCA\Talk\Manager::class)->getRoomById($roomId),
                $userId,
            );
        } catch (\Throwable $e) {
            $roomName = 'Talk room';
        }

        $budget = $maxMessages ?? $this->config->getInt('talk_index_max_messages', 200);
        $budget = max(10, min(1000, $budget));
        $comments = $this->fetchMessages($roomId, $budget);
        if ($comments === []) {
            return null;
        }
        $rendered = self::renderMessages($comments, $roomName);
        if (trim($rendered['text']) === '') {
            return null;
        }
        return [
            'roomId' => $roomId,
            'name' => $roomName,
            'text' => $rendered['text'],
            'messages' => $rendered['count'],
        ];
    }

    /**
     * The most recent messages of a room, oldest first.
     *
     * @return list<IComment>
     */
    private function fetchMessages(int $roomId, int $budget): array
    {
        try {
            $comments = $this->commentsManager->getForObject(
                self::CHAT_OBJECT_TYPE,
                (string)$roomId,
                min($budget, self::PAGE_SIZE),
                0,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: could not read Talk history: ' . $e->getMessage());
            return [];
        }
        return array_reverse(array_values((array)$comments));
    }

    /**
     * Turn Talk chat messages into plain, dated text.
     *
     * Pure and static so the formatting can be tested without Talk. The order of
     * the input is kept as given: a transcript only reads correctly oldest-first,
     * so the caller passes the messages in that order (see fetchMessages(), which
     * reverses Talk's newest-first page). System messages (changelog, calls,
     * commands, JSON payloads) are dropped - they carry no conversational content
     * and would only dilute retrieval.
     *
     * @param iterable<IComment> $messages oldest first
     * @return array{text:string,count:int}
     */
    public static function renderMessages(iterable $messages, string $roomName = ''): array
    {
        $lines = [];
        if ($roomName !== '') {
            $lines[] = 'Talk chat: ' . $roomName;
        }
        $count = 0;
        foreach ($messages as $comment) {
            if (!$comment instanceof IComment) {
                continue;
            }
            $message = trim((string)$comment->getMessage());
            if ($message === '' || str_starts_with($message, '{') || str_starts_with($message, '/')) {
                continue;
            }
            $actor = (string)$comment->getActorId();
            if ($actor === 'changelog' || str_starts_with($actor, 'bots/')) {
                continue;
            }
            $when = $comment->getCreationDateTime();
            $stamp = $when !== null ? $when->format('Y-m-d H:i') : '';
            $lines[] = '[' . $stamp . '] ' . $actor . ': ' . $message;
            $count++;
        }
        return ['text' => implode("\n", $lines), 'count' => $count];
    }

    /** A stable, human-readable name for a room. */
    private function roomLabel(object $room, string $userId): string
    {
        try {
            $name = trim((string)$room->getDisplayName($userId));
            if ($name !== '') {
                return $name;
            }
            $name = trim((string)$room->getName());
            return $name !== '' ? $name : 'Talk room ' . (int)$room->getId();
        } catch (\Throwable $e) {
            return 'Talk room';
        }
    }
}
