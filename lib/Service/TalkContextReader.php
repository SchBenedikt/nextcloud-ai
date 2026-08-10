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
    /** Default: 50 Nachrichten; konfigurierbar via AppConfig 'talk_history_size'. */
    private const DEFAULT_HISTORY = 50;

    public function __construct(
        private TalkManager $talkManager,
        private ChatManager $chatManager,
        private ICommentsManager $commentsManager,
        private AppConfig $appConfig,
    ) {
    }

    /**
     * Liefert die letzten MAX_HISTORY Chat-Nachrichten des Raums als
     * für Ollama formatierte messages-Liste. EVA's eigene Bot-Antworten
     * werden als "assistant" zurückgegeben, fremde User-/Guest-Nachrichten
     * als "user". System-Messages, Changelog und Commands werden
     * herausgefiltert.
     *
     * Format: [['role' => 'user|assistant', 'content' => '...']]
     *
     * @return list<array{role:string,content:string}>
     */
    public function buildHistoryMessages(int $roomId): array {
        $maxHistory = $this->appConfig->getInt('talk_history_size', self::DEFAULT_HISTORY);
        try {
            $comments = $this->commentsManager->getForObject(
                'chat',
                (string)$roomId,
                $maxHistory,
                0,
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        // Comments kommen DESC; wir wollen chronologisch aufsteigend.
        $reversed = array_reverse((array)$comments);
        foreach ($reversed as $c) {
            $actorType = (string)$c->getActorType();
            $actorId = (string)$c->getActorId();
            $message = trim((string)$c->getMessage());
            if ($message === '') {
                continue;
            }
            // Changelog / System-Logs raus.
            if ($actorId === Attendee::ACTOR_ID_CHANGELOG) {
                continue;
            }
            // Commands wie /me oder /help raus.
            if (str_starts_with($message, '/')) {
                continue;
            }
            // Talk-System-Messages sind JSON-Objekte, die mit { anfangen.
            if (str_starts_with($message, '{')) {
                continue;
            }
            // Eigene Bot-Antworten -> assistant.
            if ($actorType === Attendee::ACTOR_BOTS) {
                $out[] = [
                    'role' => 'assistant',
                    'content' => $message,
                ];
                continue;
            }
            // Andere Bots (fremde Apps) und Federated/Guests mit Bots
            // uninteressant; nur eigene User/Guest-Nachrichten behalten.
            if ($actorType !== Attendee::ACTOR_USERS && $actorType !== Attendee::ACTOR_GUESTS) {
                continue;
            }
            if (str_starts_with($actorId, Attendee::ACTOR_BOT_PREFIX)) {
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