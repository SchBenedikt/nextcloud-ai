<?php

declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\Service\Ollama;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Model\Bot;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * EVA-Bot für Nextcloud Talk.
 *
 * Lauscht auf BotInvokeEvent (FEATURE_RESPONSE) und antwortet im
 * Raum mit einer kurzen, freundlichen LLM-Antwort. Bewusst ohne
 * Tool-Aufrufe, um Latenz und FS-Abhängigkeiten zu vermeiden.
 *
 * @implements IEventListener<Event>
 */
class TalkBotListener implements IEventListener {
    private const SYSTEM_PROMPT = <<<'PROMPT'
Du bist EVA, ein hilfreicher KI-Assistent im Nextcloud-Talk-Chat. Antworte kurz und freundlich (1-3 Sätze) auf Deutsch. Wenn der Nutzer dich direkt anspricht (z.B. "EVA, ..." oder "@EVA"), reagiere immer. Sonst nur, wenn die Frage eindeutig an dich gerichtet ist oder du konkret gebraucht wirst.

Wichtig: Du hast im Talk-Kontext KEINE Werkzeuge (keine Kalender-, Aufgaben-, Datei- oder Mail-Tools). Wenn der Nutzer Live-Daten braucht (aktuelle Uhrzeit, Termine, Aufgaben, Datei-Inhalte), sage ehrlich "Das kann ich im Chat gerade nicht abrufen, schau bitte in der EVA-Web-App nach" – erfinde keine Uhrzeiten oder Termine. Allgemeinwissen, Erklärungen, Brainstorming, Formulierungshilfen und Smalltalk darfst du gerne beantworten.
PROMPT;

    public function __construct(
        private Ollama $ollama,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof BotInvokeEvent)) {
            return;
        }
        $url = $event->getBotUrl();
        if (!str_starts_with($url, Bot::URL_APP_PREFIX . 'eva-ai')) {
            return;
        }
        $data = $event->getMessage();
        // Nur auf Chat-Nachrichten reagieren; Reactions/System-Messages ignorieren.
        if (!isset($data['type']) || ($data['type'] !== 'Create' && $data['type'] !== 'Activity')) {
            return;
        }
        $content = trim((string)($data['object']['content'] ?? ''));
        if ($content === '') {
            return;
        }
        // Wer hat gefragt? Damit wir im user-Kontext des Sprechers antworten.
        $actorType = (string)($data['actor']['talkParticipantType'] ?? '');
        $actorName = (string)($data['actor']['name'] ?? '');
        $userId = $this->extractUserId($data['actor']['id'] ?? '');
        if ($userId === null) {
            // Bot/ anonyme Teilnehmer: ohne Nutzer-Kontext können wir keine Tools ausführen.
            $event->addAnswer("Ich kann leider nur Antworten, wenn ich weiss, von wem die Frage kommt.");
            return;
        }
        // Sprecher aus Mention-Liste entfernen, falls vorhanden.
        $cleanContent = $this->stripMention($content);

        try {
            $answer = $this->generateAnswer($userId, $cleanContent, $actorName);
            if ($answer === '') {
                $event->addAnswer("Da fällt mir gerade nichts Passendes ein. Kannst du die Frage anders stellen?");
                return;
            }
            $event->addAnswer($answer);
        } catch (\Throwable $e) {
            $this->logger->error('eva-ai talk bot failed', ['exception' => $e]);
            $event->addAnswer("Uups, da ist bei mir ein Fehler aufgetreten. Bitte versuche es gleich nochmal.");
        }
    }

    /**
     * Extrahiert die Nextcloud-User-ID aus einer Actor-ID wie
     * "users/diag9" oder "federated_users/...".
     */
    private function extractUserId(string $actorId): ?string {
        if (str_starts_with($actorId, 'users/')) {
            return substr($actorId, strlen('users/'));
        }
        // Für federated/Guests keine Tools ausführen.
        return null;
    }

    /** Entfernt "@EVA" / "@eva" aus dem Text, falls vorhanden. */
    private function stripMention(string $content): string {
        $cleaned = preg_replace('/@eva[\s,:.\-]*/iu', '', $content) ?? $content;
        return trim($cleaned);
    }

    private function generateAnswer(string $userId, string $question, string $actorName): string {
        // Im Talk-Kontext bleiben wir absichtlich ohne Tool-Aufrufe:
        //  - Tool-Calls würden jedes Mal einen UserFilesystem-Lookup brauchen,
        //    was im CLI-Hook von Talk unzuverlässig ist.
        //  - Latenz: eine 1-Satz-Antwort darf nicht 20 s Latenz erzeugen.
        // Wer Aktionen braucht (Kalender, Tasks, Files), nutzt die EVA-UI im Browser.
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT . "\nAktueller Sprecher: " . ($actorName !== '' ? $actorName : $userId)],
            ['role' => 'user', 'content' => $question],
        ];
        $resp = $this->ollama->chat($messages, []);
        if (isset($resp['error'])) {
            $this->logger->warning('eva-ai talk: ollama error: ' . $resp['error']);
            return "Leider habe ich gerade ein Verbindungsproblem zur KI.";
        }
        return trim((string)($resp['answer'] ?? ''));
    }
}