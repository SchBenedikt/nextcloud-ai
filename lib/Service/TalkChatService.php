<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\Talk\Model\Attendee;
use Psr\Log\LoggerInterface;

/**
 * Reading and writing Nextcloud Talk conversations for the assistant's tools.
 *
 * Indexing a room (see TalkTranscriptService) makes older parts of a
 * conversation *searchable*. That is not the same as reading it: when somebody
 * asks "what did we agree in the project room?", the answer must come from the
 * room as it is now, not from a snapshot taken when the index was last built.
 * This service is the live path - it lists the rooms the user is in, reads one
 * room's recent messages straight from Talk, and can post a message into a room
 * as the user who is asking.
 *
 * Two rules hold everywhere in here:
 *
 * 1. A room is only ever reachable through the user's own room list. A room id,
 *    a token or a name from the model is a *reference* that has to match one of
 *    that user's rooms - it is never used to look a room up directly. A prompt
 *    that names somebody else's conversation therefore resolves to nothing
 *    rather than to their messages.
 * 2. Posting is opt-in per user (`talk_write_enabled`, off by default) and is
 *    refused as a whole when Talk cannot be reached. A message is authored as
 *    the acting user - it is their message, which is what they asked for - so
 *    it is never sent silently or on behalf of somebody else.
 *
 * Talk is an optional dependency. No Talk class may be referenced while this
 * object is constructed, or an instance without Talk would stop booting, so
 * every entry point checks availability first and Talk services are resolved
 * lazily.
 */
class TalkChatService
{
    /** Talk's room types, so a room is describable without loading Talk. */
    private const ROOM_TYPES = [
        1 => 'one-to-one',
        2 => 'group',
        3 => 'public',
        4 => 'changelog',
        5 => 'note-to-self',
    ];

    /** Upper bound for one posted message; Talk itself allows 32000. */
    public const MAX_MESSAGE_CHARS = 4000;

    /** Upper bound for one read, so a long room cannot flood the model. */
    public const MAX_READ_MESSAGES = 200;

    public function __construct(
        private TalkTranscriptService $transcripts,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /** Whether Talk is installed and enabled, so any of this can work at all. */
    public function isAvailable(): bool
    {
        return $this->transcripts->isAvailable();
    }

    /** Whether this user allows the assistant to post messages for them. */
    public function writeEnabled(): bool
    {
        return $this->config->getInt('talk_write_enabled', 0) === 1;
    }

    /**
     * The rooms the user is a participant of, most recently active first.
     *
     * Recency and room type are included because that is how a user recognises
     * a room ("the project group we wrote in yesterday"), and it is what lets
     * the model pick the right one instead of guessing.
     *
     * @return list<array{id:int,token:string,name:string,type:string,lastActivity:string}>
     */
    public function rooms(string $userId, int $limit = 25): array
    {
        if (!$this->isAvailable() || trim($userId) === '') {
            return [];
        }
        $limit = max(1, min(100, $limit));
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
                $out[] = self::describeRoom($room, $userId);
            } catch (\Throwable $e) {
                // One unreadable room must not hide the others.
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Read the recent messages of one room the user is in.
     *
     * @return array{ok:bool,room?:array{id:int,token:string,name:string,type:string,lastActivity:string},text?:string,messages?:int,error?:string}
     */
    public function read(string $userId, string $roomRef, int $limit = 50): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'Nextcloud Talk is not installed or not enabled on this server.'];
        }
        $resolved = $this->resolveRoom($userId, $roomRef);
        if (!$resolved['ok']) {
            return ['ok' => false, 'error' => (string)$resolved['error']];
        }
        $room = $resolved['rooms'][0];
        $limit = max(5, min(self::MAX_READ_MESSAGES, $limit));
        // transcript() re-checks membership against Talk itself and returns null
        // for a room that has nothing readable in it.
        $transcript = $this->transcripts->transcript($userId, (int)$room['id'], $limit);
        if ($transcript === null) {
            return [
                'ok' => false,
                'room' => $room,
                'error' => 'That room has no readable messages (it may only contain system messages).',
            ];
        }
        return [
            'ok' => true,
            'room' => $room,
            'messages' => (int)$transcript['messages'],
            'text' => (string)$transcript['text'],
        ];
    }

    /**
     * Post a message into one of the user's rooms, authored as that user.
     *
     * @return array{ok:bool,room?:array{id:int,token:string,name:string,type:string,lastActivity:string},messageId?:int,sentAt?:string,error?:string}
     */
    public function send(string $userId, string $roomRef, string $message): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'Nextcloud Talk is not installed or not enabled on this server.'];
        }
        if (!$this->writeEnabled()) {
            return [
                'ok' => false,
                'error' => 'Posting to Nextcloud Talk is switched off. Enable "Let the assistant post to Talk for me" in the EVA AI settings first.',
            ];
        }
        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'error' => 'The message must not be empty.'];
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_CHARS) {
            return [
                'ok' => false,
                'error' => 'The message is too long (' . mb_strlen($message) . ' characters, at most ' . self::MAX_MESSAGE_CHARS . ' are allowed).',
            ];
        }

        $resolved = $this->resolveRoom($userId, $roomRef);
        if (!$resolved['ok']) {
            return ['ok' => false, 'error' => (string)$resolved['error']];
        }
        $room = $resolved['rooms'][0];

        try {
            $talkRoom = \OCP\Server::get(\OCA\Talk\Manager::class)->getRoomById((int)$room['id']);
            // Resolving the participant is the authorization check and the
            // actor the message needs: it throws when the user is not in the
            // room, so no message can be posted into a room they left.
            $participant = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class)
                ->getParticipant($talkRoom, $userId, false);
            $comment = \OCP\Server::get(\OCA\Talk\Chat\ChatManager::class)->sendMessage(
                $talkRoom,
                $participant,
                Attendee::ACTOR_USERS,
                $userId,
                $message,
                new \DateTime('now', new \DateTimeZone('UTC')),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai: could not post a Talk message', ['exception' => $e->getMessage()]);
            return [
                'ok' => false,
                'room' => $room,
                'error' => 'The message could not be posted: ' . $e->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'room' => $room,
            'messageId' => (int)$comment->getId(),
            'sentAt' => (string)$comment->getCreationDateTime()->format('Y-m-d H:i'),
        ];
    }

    /**
     * Resolve a room reference from the model to one of the user's own rooms.
     *
     * Only the user's own room list is searched, and only a single unambiguous
     * match is accepted: a name that fits two rooms is reported as ambiguous
     * instead of silently picking one.
     *
     * @return array{ok:bool,rooms:list<array{id:int,token:string,name:string,type:string,lastActivity:string}>,error?:string}
     */
    public function resolveRoom(string $userId, string $roomRef): array
    {
        $rooms = $this->rooms($userId, 100);
        if ($rooms === []) {
            return ['ok' => false, 'rooms' => [], 'error' => 'You are not a member of any Nextcloud Talk room.'];
        }
        $index = self::indexRooms($rooms);
        $matches = self::matchRoom($index, $roomRef);

        if (count($matches) === 1) {
            return ['ok' => true, 'rooms' => [$matches[0]]];
        }
        if (count($matches) > 1) {
            $names = array_map(static fn(array $r): string => (string)$r['name'], $matches);
            return [
                'ok' => false,
                'rooms' => $matches,
                'error' => 'More than one room matches "'
                    . trim($roomRef) . '": ' . implode(', ', array_slice($names, 0, 10))
                    . '. Ask again with the exact room name.',
            ];
        }
        $names = array_map(static fn(array $r): string => (string)$r['name'], array_slice($rooms, 0, 20));
        return [
            'ok' => false,
            'rooms' => [],
            'error' => 'No room of yours matches "' . trim($roomRef) . '". Your rooms are: ' . implode(', ', $names),
        ];
    }

    /**
     * Lookup table for the room references a model can produce: the name, the
     * token and the numeric id. Pure, so the matching rules are testable
     * without Talk.
     *
     * @param list<array{id:int,token:string,name:string,type:string,lastActivity:string}> $rooms
     * @return array<string,list<array{id:int,token:string,name:string,type:string,lastActivity:string}>>
     */
    public static function indexRooms(array $rooms): array
    {
        $index = [];
        foreach ($rooms as $room) {
            foreach ([
                (string)$room['name'],
                (string)$room['token'],
                (string)$room['id'],
            ] as $key) {
                $key = self::normalizeKey($key);
                if ($key === '') {
                    continue;
                }
                $index[$key][] = $room;
            }
        }
        return $index;
    }

    /**
     * Match a reference against the index: exact (case-insensitive) first, then
     * the same again ignoring spaces, "#" and "-" so "#projekt-alpha" and
     * "Projekt Alpha" both find the same room. Partial matches are only used
     * when nothing matches exactly.
     *
     * @param array<string,list<array{id:int,token:string,name:string,type:string,lastActivity:string}>> $index
     * @return list<array{id:int,token:string,name:string,type:string,lastActivity:string}>
     */
    public static function matchRoom(array $index, string $roomRef): array
    {
        $needle = self::normalizeKey($roomRef);
        if ($needle === '') {
            return [];
        }
        if (isset($index[$needle])) {
            return $index[$needle];
        }
        $loose = self::looseKey($needle);
        if ($loose === '') {
            return [];
        }
        foreach ($index as $key => $rooms) {
            if (self::looseKey((string)$key) === $loose) {
                return $rooms;
            }
        }
        // Substring as a last resort: "project" may find "Project Alpha".
        $found = [];
        foreach ($index as $key => $rooms) {
            // Array keys that look like integers come back as ints, and a room
            // id is one of the keys, so the key is always cast here.
            if (str_contains(self::looseKey((string)$key), $loose)) {
                foreach ($rooms as $room) {
                    $found[(int)$room['id']] = $room;
                }
            }
        }
        return array_values($found);
    }

    /** A room described the same way everywhere in this class. */
    private static function describeRoom(object $room, string $userId): array
    {
        $name = '';
        try {
            $name = trim((string)$room->getDisplayName($userId));
        } catch (\Throwable $e) {
            $name = '';
        }
        if ($name === '') {
            try {
                $name = trim((string)$room->getName());
            } catch (\Throwable $e) {
                $name = '';
            }
        }
        if ($name === '') {
            $name = 'Talk room ' . (int)$room->getId();
        }

        $type = 'group';
        try {
            $type = self::ROOM_TYPES[(int)$room->getType()] ?? 'group';
        } catch (\Throwable $e) {
            $type = 'group';
        }

        $lastActivity = '';
        try {
            $when = $room->getLastActivity();
            if ($when instanceof \DateTimeInterface) {
                $lastActivity = $when->format('Y-m-d H:i');
            }
        } catch (\Throwable $e) {
            $lastActivity = '';
        }

        return [
            'id' => (int)$room->getId(),
            'token' => (string)$room->getToken(),
            'name' => $name,
            'type' => $type,
            'lastActivity' => $lastActivity,
        ];
    }

    private static function normalizeKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** Comparison key with the punctuation a room reference tends to carry. */
    private static function looseKey(string $value): string
    {
        return preg_replace('/[\s#\-_]+/u', '', self::normalizeKey($value)) ?? '';
    }
}
