<?php

declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\TalkContextReader;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Model\Bot;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * EVA-Bot für Nextcloud Talk mit Tool-Unterstützung.
 *
 * Lauscht auf BotInvokeEvent (FEATURE_EVENT) und antwortet im Raum mit
 * einer LLM-Antwort. Unterstützt automatische Aktionen (Kalender, Tasks,
 * Dateien, Kontakte etc.) OHNE Bestätigung im Talk-Kontext.
 *
 * Selektive Antwort-Logik (Option 4):
 * - @EVA/@eva Erwähnung → immer antworten
 * - Custom Trigger (konfigurierbar) → immer antworten
 * - Name "EVA" ohne @ → LLM-Klassifikation mit Teilnehmer-Info
 * - Frage an KI → antworten (LLM-basierte Klassifikation)
 *
 * @implements IEventListener<Event>
 */
class TalkBotListener implements IEventListener {
    private const SYSTEM_PROMPT = <<<'PROMPT'
Du bist EVA, ein hilfreicher KI-Assistent im Nextcloud-Talk-Chat. Antworte kurz und freundlich (1-3 Sätze) auf Deutsch.

Du hast Zugriff auf Werkzeuge (Kalender, Tasks, Dateien, Kontakte, Mail etc.). Wenn der Nutzer eine konkrete Aktion möchte (z.B. "erstelle einen Termin", "was sind meine Aufgaben?"), führe sie automatisch aus – ohne nachzufragen. Nutze die bereitgestellten Tools direkt.

Wichtig: Führe Aktionen IMMER automatisch aus, wenn der Nutzer sie eindeutig anfordert. Stelle keine unnötigen Bestätigungsfragen. Antworte direkt mit dem Ergebnis.

Beachte den Chat-Verlauf: Wenn der Nutzer auf Vorgänger verweist (z.B. "was hat X eben gesagt?"), beziehe dich darauf. Wenn die letzte Nachricht nicht an dich gerichtet war, antworte nur, wenn die Frage eindeutig an dich gerichtet ist.
PROMPT;

    public function __construct(
        private Ollama $ollama,
        private TalkContextReader $contextReader,
        private ActionExecutor $executor,
        private AppConfig $appConfig,
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
        $actorName = (string)($data['actor']['name'] ?? '');
        $userId = $this->extractUserId($data['actor']['id'] ?? '');
        if ($userId === null) {
            $event->addAnswer("Ich kann leider nur Antworten, wenn ich weiss, von wem die Frage kommt.");
            return;
        }

        // Selektive Antwort-Logik: Nur antworten wenn angesprochen.
        $roomId = (int)($data['target']['id'] ?? 0);
        if (!$this->shouldRespond($content, $userId, $roomId)) {
            return; // Stille – keine Antwort.
        }

        // Sprecher aus Mention-Liste entfernen, falls vorhanden.
        $cleanContent = $this->stripMention($content);

        // History der letzten Chatnachrichten laden.
        $history = $roomId > 0 ? $this->contextReader->buildHistoryMessages($roomId) : [];

        try {
            $answer = $this->generateAnswerWithTools($history, $cleanContent, $actorName, $userId);
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
     * Entscheidet ob EVA antworten soll.
     *
     * Trigger (Option 4):
     * 1. @EVA/@eva Erwähnung → immer antworten
     * 2. Custom Trigger (konfigurierbar) → immer antworten
     * 3. Name "EVA" ohne @ → LLM-Klassifikation mit Teilnehmer-Info
     * 4. Frage mit "?" → LLM-Klassifikation
     * 5. Imperative Anweisungen ohne "?" → LLM-Klassifikation
     * 6. Sonst → schweigen
     */
    private function shouldRespond(string $content, string $currentUserId, int $roomId): bool {
        // 1. @EVA/@eva Erwähnung – schneller Check
        if (preg_match('/@eva\b/i', $content)) {
            return true;
        }

        // 2. Custom Trigger (konfigurierbar) – z.B. "@EVABot" oder "EVA-Bot"
        $customTrigger = $this->appConfig->get('talk_bot_trigger');
        if ($customTrigger !== '' && preg_match('/' . preg_quote($customTrigger, '/') . '\b/i', $content)) {
            return true;
        }

        // 3. Name "EVA" ohne @ → LLM-Klassifikation mit Teilnehmer-Info
        if (preg_match('/\bEVA\b/i', $content)) {
            return $this->isNameMentionForBot($content, $roomId);
        }

        // 4. Frage mit "?" → LLM-basierte Klassifikation
        if (str_contains($content, '?')) {
            return $this->isQuestionForEva($content, $roomId);
        }

        // 5. Imperative Anweisungen / Aktionsanfragen ohne Fragezeichen
        //    z.B. "Erstelle einen Termin", "Zeig meine Aufgaben", "Was ist heute"
        if ($this->looksLikeActionRequest($content)) {
            return $this->isQuestionForEva($content, $roomId);
        }

        return false;
    }

    /**
     * Prüft ob der Text wie eine Aktionsanfrage aussieht (ohne Fragezeichen).
     * Diese Muster deuten typischerweise auf eine KI-Anfrage hin.
     */
    private function looksLikeActionRequest(string $content): bool {
        $lower = strtolower($content);
        // Imperative und häufige KI-Anfragen-Muster
        $patterns = [
            '/\b(erstelle|create)\b/',
            '/\b(zeig|zeige|zeigen|show)\b/',
            '/\b(liste|list)\b/',
            '/\b(suche|finde)\b/',
            '/\b(was ist|was sind|wie ist|wie sind)\b/',
            '/\b(kannst du|könntest du)\b/',
            '/\b(mach|machen)\b/',
            '/\b(öffne|oeffne)\b/',
            '/\b(schreibe|schreiben)\b/',
            '/\b(löschen|entfernen|remove)\b/',
            '/\b(bearbeite|update)\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }
        return false;
    }

    /**
     * LLM-basierte Klassifikation: Ist diese "EVA"-Erwähnung für den Bot?
     *
     * Berücksichtigt Chat-Teilnehmer, um zwischen dem Bot und einer echten
     * Person namens Eva zu unterscheiden.
     */
    private function isNameMentionForBot(string $content, int $roomId): bool {
        $participants = $this->getRoomParticipantNames($roomId);
        $participantInfo = $participants !== [] ? 'Chat-Teilnehmer: ' . implode(', ', $participants) : 'Keine Teilnehmer-Informationen verfügbar.';

        $messages = [
            ['role' => 'system', 'content' => 'Du bist ein Klassifikator. Antworte NUR mit "ja" oder "nein". '
                . 'Ist diese Nachricht an den KI-Assistenten "EVA" gerichtet (nicht an eine echte Person)? '
                . $participantInfo . ' '
                . 'Beachte: Wenn eine echte Person namens "EVA" im Chat ist und die Nachricht an diese Person gerichtet scheint (z.B. "EVA, kannst du das machen?"), antworte mit "nein". '
                . 'Wenn die Nachricht eindeutig an den KI-Assistenten gerichtet ist (z.B. "EVA, erstelle einen Termin"), antworte mit "ja".'],
            ['role' => 'user', 'content' => $content],
        ];

        $resp = $this->ollama->chat($messages, []);
        if (isset($resp['error'])) {
            $this->logger->warning('eva-ai talk: name classification error: ' . $resp['error']);
            return false; // Bei Fehler: nicht antworten (sicherer)
        }

        $answer = strtolower(trim((string)($resp['answer'] ?? '')));
        return str_starts_with($answer, 'ja');
    }

    /**
     * LLM-basierte Klassifikation: Ist diese Frage an EVA gerichtet?
     *
     * Nutzt einen schnellen LLM-Call ohne Tools, um zu entscheiden ob die
     * Frage an den KI-Assistenten gerichtet ist oder an andere Chat-Teilnehmer.
     */
    private function isQuestionForEva(string $content, int $roomId): bool {
        $participants = $this->getRoomParticipantNames($roomId);
        $participantInfo = $participants !== [] ? 'Chat-Teilnehmer: ' . implode(', ', $participants) : 'Keine Teilnehmer-Informationen verfügbar.';

        $messages = [
            ['role' => 'system', 'content' => 'Du bist ein Klassifikator. Antworte NUR mit "ja" oder "nein". '
                . 'Ist diese Frage an den KI-Assistenten "EVA" gerichtet? '
                . $participantInfo . ' '
                . 'Beachte: Fragened an andere Personen im Chat (z.B. "Kannst du das machen?") sind NEIN. '
                . 'Fragen nach Informationen, die nur eine KI beantworten kann (Erklärungen, Wissen, Berechnungen), sind JA. '
                . 'Fragen die mit "EVA" beginnen sind IMMER JA.'],
            ['role' => 'user', 'content' => $content],
        ];

        $resp = $this->ollama->chat($messages, []);
        if (isset($resp['error'])) {
            $this->logger->warning('eva-ai talk: classification error: ' . $resp['error']);
            return false; // Bei Fehler: nicht antworten (sicherer)
        }

        $answer = strtolower(trim((string)($resp['answer'] ?? '')));
        return str_starts_with($answer, 'ja');
    }

    /**
     * Holt die Namen der Chat-Teilnehmer für die Klassifikation.
     *
     * @return list<string>
     */
    private function getRoomParticipantNames(int $roomId): array {
        if ($roomId <= 0) {
            return [];
        }

        try {
            // TalkManager nutzen um Room zu bekommen
            $roomManager = \OC::$server->get(\OCA\Talk\Manager::class);
            $room = $roomManager->getRoomById($roomId);
            if ($room === null) {
                return [];
            }

            // ParticipantService nutzen um Teilnehmer zu bekommen
            $participantService = \OC::$server->get(\OCA\Talk\Service\ParticipantService::class);
            $participants = $participantService->getParticipantsForRoom($room);

            $names = [];
            foreach ($participants as $participant) {
                $actorType = $participant->getActorType();
                $actorId = $participant->getActorId();

                // Nur User und Guests berücksichtigen
                if ($actorType === 'users' || $actorType === 'guests') {
                    // Bei Users: Display-Name holen
                    if ($actorType === 'users') {
                        $userManager = \OC::$server->get(\OCP\IUserManager::class);
                        $user = $userManager->get($actorId);
                        if ($user !== null) {
                            $names[] = $user->getDisplayName();
                        } else {
                            $names[] = $actorId;
                        }
                    } else {
                        // Guests: Actor-ID als Name verwenden
                        $names[] = $actorId;
                    }
                }
            }

            return $names;
        } catch (\Throwable $e) {
            $this->logger->warning('eva-ai talk: could not get participants: ' . $e->getMessage());
            return [];
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

    /** Entfernt "@EVA" / "@eva" und Custom Trigger aus dem Text. */
    private function stripMention(string $content): string {
        // @EVA/@eva entfernen
        $cleaned = preg_replace('/@eva[\s,:.\-]*/iu', '', $content) ?? $content;

        // Custom Trigger entfernen
        $customTrigger = $this->appConfig->get('talk_bot_trigger');
        if ($customTrigger !== '') {
            $cleaned = preg_replace('/' . preg_quote($customTrigger, '/') . '[\s,:.\-]*/i', '', $cleaned) ?? $cleaned;
        }

        return trim($cleaned);
    }

    /**
     * Generiert eine Antwort MIT Tool-Unterstützung.
     *
     * Flow:
     * 1. LLM mit Tools aufrufen
     * 2. Bei Tool-Calls: diese automatisch ausführen (kein Bestätigungsdialog)
     * 3. Ergebnisse zurück an LLM für finale Antwort
     * 4. Bis zu 3 Runden erlauben (für mehrstufige Aktionen)
     *
     * @param list<array{role:string,content:string}> $history
     */
    private function generateAnswerWithTools(array $history, string $question, string $actorName, string $userId): string {
        $system = self::SYSTEM_PROMPT . "\nAktueller Sprecher: " . ($actorName !== '' ? $actorName : $userId);
        $messages = [
            ['role' => 'system', 'content' => $system],
        ];
        // History einfügen (chronologisch aufsteigend)
        foreach ($history as $h) {
            $messages[] = $h;
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $tools = $this->executor->tools();

        // Bis zu 3 Runden: LLM ruft Tools, wir führen sie aus, Ergebnis geht zurück.
        for ($round = 0; $round < 3; $round++) {
            $chat = $this->ollama->chat($messages, $tools);
            if (isset($chat['error'])) {
                $this->logger->warning('eva-ai talk: ollama error: ' . $chat['error']);
                return "Leider habe ich gerade ein Verbindungsproblem zur KI.";
            }

            $answer = (string)($chat['answer'] ?? '');
            $rawCalls = $chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? [];
            $calls = $this->normalizeToolCalls($rawCalls);

            // Keine Tool-Calls → fertig, Antwort zurückgeben.
            if ($calls === []) {
                return $answer;
            }

            // Tools automatisch ausführen (kein Bestätigungsdialog im Talk).
            $ranAny = false;
            foreach ($calls as $tc) {
                $name = (string)($tc['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $args = is_array($tc['args'] ?? null) ? $tc['args'] : [];

                try {
                    $res = $this->executor->run($userId, $name, $args);
                } catch (\Throwable $e) {
                    $res = ['ok' => false, 'error' => $e->getMessage()];
                }

                $ranAny = true;
                $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => $this->canonical([$tc])];
                $messages[] = ['role' => 'tool', 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }

            // Wenn keine Tools ausgeführt wurden (nur nicht-erkannte Calls), abbrechen.
            if (!$ranAny) {
                return $answer !== '' ? $answer : "Das konnte ich leider nicht verstehen.";
            }
        }

        // Nach 3 Runden: finale Antwort ohne Tools.
        $final = $this->ollama->chat($messages, []);
        return trim((string)($final['answer'] ?? ''));
    }

    /**
     * Normalisiert Tool-Calls in ein einheitliches Format.
     *
     * @param array<int,array> $raw
     * @return list<array{name:string,args:array}>
     */
    private function normalizeToolCalls(array $raw): array {
        $out = [];
        foreach ($raw as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $fn = $tc['function'] ?? $tc;
            $name = (string)($fn['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $args = $fn['arguments'] ?? $fn['args'] ?? [];
            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($args)) {
                $args = [];
            }
            $out[] = ['name' => $name, 'args' => $args];
        }
        return $out;
    }

    /**
     * Wandelt Tool-Calls in das kanonische Format für Ollama um.
     *
     * @param list<array{name:string,args:array}> $raw
     * @return list<array{id:string,type:string,function:array{name:string,arguments:object}}>
     */
    private function canonical(array $raw): array {
        $out = [];
        foreach ($raw as $tc) {
            $obj = new \stdClass();
            $args = $tc['args'] ?? [];
            foreach ($args as $k => $v) {
                $obj->{$k} = $v;
            }
            $out[] = [
                'id' => 'call_' . bin2hex(random_bytes(4)),
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'],
                    'arguments' => $obj,
                ],
            ];
        }
        return $out;
    }
}
