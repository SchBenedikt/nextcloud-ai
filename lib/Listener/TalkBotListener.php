<?php

declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkContextReader;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Model\Bot;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * EVA-Bot für Nextcloud Talk mit Tool-Unterstützung und RAG.
 *
 * Lauscht auf BotInvokeEvent (FEATURE_EVENT) und antwortet im Raum mit
 * einer LLM-Antwort. Nutzt RagService für:
 * - Vektor-Suche in indexierten Dateien (RAG)
 * - Automatische Aktionen (Kalender, Tasks, Dateien, Kontakte etc.) OHNE
 *   Bestätigung im Talk-Kontext
 *
 * Selektive Antwort-Logik (keine Pattern-basierte Filterung):
 * - @EVA/@eva Erwähnung → immer antworten (explizite Adressierung)
 * - Custom Trigger (konfigurierbar) → immer antworten
 * - Sonst: KI-Klassifikation anhand Inhalt und Chat-Teilnehmer entscheidet
 *
 * @implements IEventListener<Event>
 */
class TalkBotListener implements IEventListener {
    private const SYSTEM_PROMPT = <<<'PROMPT'
Du bist EVA, ein hilfreicher KI-Assistent im Nextcloud-Talk-Chat. Antworte kurz und freundlich (1-3 Sätze) auf Deutsch.

Du hast Zugriff auf Werkzeuge (Kalender, Tasks, Dateien, Kontakte, Mail etc.). Wenn der Nutzer eine konkrete Aktion möchte (z.B. "erstelle einen Termin", "was sind meine Aufgaben?"), führe sie automatisch aus – ohne nachzufragen. Nutze die bereitgestellten Tools direkt.

Wichtig: Führe Aktionen IMMER automatisch aus, wenn der Nutzer sie eindeutig anfordert.
PROMPT;

    public function __construct(
        private Ollama $ollama,
        private TalkContextReader $contextReader,
        private ActionExecutor $executor,
        private AppConfig $appConfig,
        private RagService $ragService,
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
        $explicit = $this->isExplicitlyMentioned($content);
        if (!$this->shouldRespond($content, $userId, $roomId, $explicit)) {
            return; // Stille – keine Antwort.
        }

        // Sprecher aus Mention-Liste entfernen, falls vorhanden.
        $cleanContent = $this->stripMention($content);

        // History der letzten Chatnachrichten laden.
        $history = $roomId > 0 ? $this->contextReader->buildHistoryMessages($roomId) : [];

        try {
            $answer = $this->generateAnswerWithRag($history, $cleanContent, $actorName, $userId);
            if (trim($answer) === '') {
                // Ohne Antwort NUR posten, wenn EVA explizit angesprochen wurde
                // (z.B. "@Eva …"). Bei einer rein klassifizierten Nachricht
                // schweigen wir, damit wir nicht unnötig Lärm in den Chat schreiben.
                if (!$explicit) {
                    return;
                }
                $event->addAnswer("Da fällt mir gerade nichts Passendes ein. Kannst du die Frage anders stellen?");
                return;
            }
            $event->addAnswer($answer);
        } catch (\Throwable $e) {
            $this->logger->error('eva-ai talk bot failed', ['exception' => $e]);
            $event->addAnswer("Uups, da ist bei mir ein Fehler aufgetreten. Bitte versuche es gleich nochmal.");
        }
    }

    /** Prüft, ob EVA explizit per @Mention oder Custom-Trigger angesprochen wurde. */
    private function isExplicitlyMentioned(string $content): bool {
        if (preg_match('/@eva\b/i', $content)) {
            return true;
        }
        $triggerName = $this->appConfig->get('talk_bot_trigger');
        if ($triggerName !== '' && preg_match('/@' . preg_quote($triggerName, '/') . '\b/iu', $content)) {
            return true;
        }
        return false;
    }

    /**
     * Entscheidet ob EVA antworten soll.
     *
     * KEINE pattern-basierte Filterung. Die KI entscheidet für jede Nachricht:
     * 1. @EVA/@eva/Custom-Trigger Erwähnung → immer antworten (explizite Adressierung)
     * 2. Sonst: KI-Klassifikation anhand Inhalt und Teilnehmer
     */
    private function shouldRespond(string $content, string $currentUserId, int $roomId, bool $explicit = false): bool {
        // 1. @EVA/@eva/Custom-Trigger Erwähnung – schneller Check (explizite Adressierung)
        if ($explicit) {
            return true;
        }

        // 2. KI entscheidet für jede Nachricht
        $triggerName = $this->appConfig->get('talk_bot_trigger');
        return $this->classificationForEva($content, $roomId, $triggerName);
    }

    /**
     * KI-basierte Klassifikation: Ist diese Nachricht an den KI-Assistenten EVA?
     *
     * Die KI entscheidet für JEDE Nachricht anhand von Inhalt und Chat-Teilnehmern.
     * KEINE pattern-basierte Filterung.
     */
    private function classificationForEva(string $content, int $roomId, string $triggerName): bool {
        $participants = $this->getRoomParticipantNames($roomId);
        $participantInfo = $participants !== [] ? "\nChat-Teilnehmer: " . implode(', ', $participants) . "\n" : "\nKeine Teilnehmer-Informationen verfügbar.\n";

        $messages = [
            ['role' => 'system', 'content' => 'Du bist ein KI-Assistent namens "' . $triggerName . '". '
                . 'Dein Name ist also: ' . $triggerName . '. '
                . $participantInfo . ' '
                . 'ANTWORTE NUR mit "ja" oder "nein". '
                . 'Ist diese Nachricht für DICH (den KI-Assistenten) bestimmt? '
                . 'Ja, wenn: eine Frage an dich gerichtet ist, eine Aktion von dir erwartet wird, '
                . 'oder klar erkennbar an die KI gerichtet ist. '
                . 'Nein, wenn: eine Nachricht an eine andere Person, Smalltalk zwischen anderen, '
                . 'oder eine Bemerkung die nicht an die KI gerichtet ist. '
                . 'Wenn eine echte Person mit demselben Namen im Chat ist und du unsicher bist, antworte mit "nein".'],
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

    /** Entfernt "@EVA" / "@eva" und Custom Trigger Mentions aus dem Text.
     *  Entfernt NUR erwähnte Namen (mit @), nicht den reinen Namen,
     *  da dieser ggf. auf eine reale Person verweisen könnte.
     */
    private function stripMention(string $content): string {
        // @EVA/@eva und @CustomTrigger entfernen (nur mit @!)
        $customTrigger = $this->appConfig->get('talk_bot_trigger');
        if ($customTrigger !== '') {
            $content = preg_replace(
                '/@(?:' . preg_quote($customTrigger, '/') . '|eva)[\s,:.\-]*/iu',
                '',
                $content
            ) ?? $content;
        } else {
            $content = preg_replace('/@eva[\s,:.\-]*/iu', '', $content) ?? $content;
        }

        return trim($content);
    }

    /**
     * Generiert eine Antwort mit RAG (Retrieval-Augmented Generation) und
     * Tool-Unterstützung, identisch zur EVA-Web-App.
     *
     * Nutzt RagService::ask() für:
     * - Vektor-Suche in indexierten Dateien/Wissen
     * - Tool-Aufrufe (Kalender, Tasks, Dateien, etc.)
     * - LLM-Antwort generieren
     *
     * @param list<array{role:string,content:string}> $history
     */
    private function generateAnswerWithRag(array $history, string $question, string $actorName, string $userId): string {
        // RagService::ask() macht Vector-Search + Tool-Execution + LLM-Antwort
        $result = $this->ragService->ask($userId, $question, $history);

        if (isset($result['error']) && $result['error'] !== '') {
            $this->logger->warning('eva-ai talk: rag error: ' . $result['error']);
            // Bei Vektor-Fehler: fallback auf reinen LLM-Chat mit Tools
            return $this->fallbackAnswer($history, $question, $actorName, $userId);
        }

        $answer = trim((string)($result['answer'] ?? ''));

        // Sources als Fußnote anhängen, falls vorhanden
        $sources = $result['sources'] ?? [];
        if ($sources !== []) {
            $sourceRefs = [];
            foreach ($sources as $s) {
                $sourceRefs[] = (string)($s['name'] ?? $s['path'] ?? 'Quelle');
            }
            if ($sourceRefs !== []) {
                $answer .= "\n\n_Quellen: " . implode(', ', $sourceRefs) . "_";
            }
        }

        return $answer;
    }

    /**
     * Fallback: reiner LLM-Chat mit Tools, falls RAG fehlschlägt
     * (z.B. kein indexiertes Material vorhanden).
     *
     * @param list<array{role:string,content:string}> $history
     */
    private function fallbackAnswer(array $history, string $question, string $actorName, string $userId): string {
        $system = self::SYSTEM_PROMPT . "\nAktueller Sprecher: " . ($actorName !== '' ? $actorName : $userId);
        $messages = [
            ['role' => 'system', 'content' => $system],
        ];
        foreach ($history as $h) {
            $messages[] = $h;
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $tools = $this->executor->tools();

        for ($round = 0; $round < 3; $round++) {
            $chat = $this->ollama->chat($messages, $tools);
            if (isset($chat['error'])) {
                $this->logger->warning('eva-ai talk: fallback ollama error: ' . $chat['error']);
                return "Leider habe ich gerade ein Verbindungsproblem zur KI.";
            }

            $answer = (string)($chat['answer'] ?? '');
            $rawCalls = $chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? [];
            $calls = $this->normalizeToolCalls($rawCalls);

            if ($calls === []) {
                return $answer;
            }

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
            if (!$ranAny) {
                return $answer !== '' ? $answer : "Das konnte ich leider nicht verstehen.";
            }
        }

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
