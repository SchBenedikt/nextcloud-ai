<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Model\Attendee;
use OCP\Comments\ICommentsManager;

/**
 * Liest den Talk-Kontext (Room + letzte Nachrichten) für den Bot-Listener.
 *
 * Zweck: Dem EVA-Talk-Bot die letzten N Chatnachrichten des Raums geben,
 * damit er Bezug auf Vorgänger nehmen kann ("wer hat was wann gesagt?").
 */
class TalkContextReader {
    /** Maximale Anzahl History-Nachrichten, die wir dem Modell geben. */
    public const MAX_HISTORY = 15;

    public function __construct(
        private TalkManager $talkManager,
        private ChatManager $chatManager,
        private ICommentsManager $commentsManager,
    ) {
    }

    /**
     * Liefert die letzten MAX_HISTORY Chat-Nachrichten des Raums als
     * für Ollama formatierte messages-Liste. Bots, System-Messages und
     * Commands werden herausgefiltert.
     *
     * Format: [['role' => 'user|assistant', 'content' => '...']]
     * - role 'user' für normale User-Nachrichten
     * - role 'assistant' für vorherige Bot-Antworten von EVA
     *
     * @return list<array{role:string,content:string}>
     */
    public function buildHistoryMessages(int $roomId): array {
        try {
            $comments = $this->commentsManager->getForObject(
                'chat',
                (string)$roomId,
                self::MAX_HISTORY,
                0,
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        // Comments kommen DESC; wir wollen chronologisch.
        $reversed = array_reverse((array)$comments);
        foreach ($reversed as $c) {
            $actorType = (string)$c->getActorType();
            $actorId = (string)$c->getActorId();
            $message = trim((string)$c->getMessage());
            if ($message === '') {
                continue;
            }
            // System-/Bot-/Changelog-Messages filtern.
            if ($actorType !== Attendee::ACTOR_USERS && $actorType !== Attendee::ACTOR_GUESTS) {
                continue;
            }
            if (str_starts_with($actorId, Attendee::ACTOR_BOT_PREFIX) || $actorId === Attendee::ACTOR_ID_CHANGELOG) {
                continue;
            }
            // Commands, die mit '/' beginnen, ignorieren (UI-Rauschen).
            if (str_starts_with($message, '/')) {
                continue;
            }
            // Mentions und Talk-System-Messages (JSON) überspringen.
            if (str_starts_with($message, '{')) {
                continue;
            }
            $out[] = [
                'role' => 'user',
                'content' => $actorId . ': ' . $message,
            ];
        }
        return $out;
    }
}