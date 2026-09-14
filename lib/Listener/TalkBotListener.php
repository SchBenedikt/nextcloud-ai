<?php

declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkContextReader;
use OCA\EvaAi\Service\TalkRoomState;
use OCA\EvaAi\Service\TalkTranscriptService;
use OCA\EvaAi\Service\ToolPolicy;
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
 * - Read-only tools (Kalender, Tasks, Dateien, Kontakte etc.) im Talk-Kontext;
 *   mutating and destructive tools are deliberately unavailable
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
You are EVA, a helpful assistant in a Nextcloud Talk conversation. Answer briefly and in a friendly tone (1-3 sentences), in the language of the message you are answering.

You have read-only tools (calendar, tasks, files, contacts, mail, …). Use them when the user asks for information.

You also have a web search tool (`web_search`), a page reader (`open_website`) and an image search tool (`search_images`). Use the web search proactively when you need current or time-critical information: news, software releases, prices, weather, documentation, opening hours, recipes, how-to guides or technical problems. When you are unsure whether your training data is still current, search the internet instead of guessing. When the user asks to see pictures of something, use `search_images` and embed the pictures with Markdown image syntax - you can display images, so never answer that you cannot.

Important: in Talk you cannot create, change or delete files, contacts, calendar entries, shares or tasks. Say so briefly and point to the EVA web chat for those actions, where an explicit confirmation is required.
PROMPT;

    public function __construct(
        private Ollama $ollama,
        private TalkContextReader $contextReader,
        private ActionExecutor $executor,
        private AppConfig $appConfig,
        private RagService $ragService,
        private TalkRoomState $roomState,
        private TalkTranscriptService $talkTranscripts,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        // Set tool policy surface to Talk (per request, at execution time)
        $this->executor->setSurface(ToolPolicy::SURFACE_TALK);

        if (!($event instanceof BotInvokeEvent)) {
            return;
        }
        $url = $event->getBotUrl();
        if (!str_starts_with($url, Bot::URL_APP_PREFIX . 'eva_ai')) {
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
            $event->addAnswer('I can only answer when I can tell who is asking.');
            return;
        }

        $this->appConfig->setUserId($userId);
        $this->executor->setUserId($userId);

        $roomId = (int)($data['target']['id'] ?? 0);
        $explicit = $this->isExplicitlyMentioned($content);
        if (!$explicit) {
            return;
        }

        // Per-room enable/disable (Issue #85): a disabled room stays silent
        // for everything except the /start command itself, so the bot can
        // always be re-enabled from the chat.
        if (!$this->roomState->isEnabled($roomId)
            && !$this->parseSlashCommand($content)['start']) {
            return;
        }

        // Deterministic slash commands (Issue #85): handled before any LLM
        // classification, so @Eva /help etc. never depend on the model.
        $command = $this->parseSlashCommand($content);
        if ($command['name'] !== '') {
            $this->handleSlashCommand($event, $command, $userId, $roomId);
            return;
        }

        // Selektive Antwort-Logik: Nur antworten wenn angesprochen.
        if (!$this->shouldRespond($content, $userId, $roomId, $explicit)) {
            return; // Stille – keine Antwort.
        }

        // Sprecher aus Mention-Liste entfernen, falls vorhanden.
        $cleanContent = $this->stripMention($content);

        // History der letzten Chatnachrichten laden.
        $history = $roomId > 0 ? $this->contextReader->buildHistoryMessages($roomId) : [];

        try {
            $answer = $this->generateAnswerWithRag($history, $cleanContent, $actorName, $userId, $roomId);
            if (trim($answer) === '') {
                // Ohne Antwort NUR posten, wenn EVA explizit angesprochen wurde
                // (z.B. "@Eva …"). Bei einer rein klassifizierten Nachricht
                // schweigen wir, damit wir nicht unnötig Lärm in den Chat schreiben.
                if (!$explicit) {
                    return;
                }
                $event->addAnswer('I cannot think of a good answer right now. Could you rephrase the question?');
                return;
            }
            $event->addAnswer($answer);
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai talk bot failed', ['exception' => $e]);
            $event->addAnswer('Something went wrong on my side. Please try again in a moment.');
        }
    }

    /**
     * Parse a deterministic slash command (Issue #85). The mention itself is
     * stripped first, so "@Eva /help", "@Eva /summarize" and "@eva /status"
     * all work. Returns an empty name for plain messages.
     *
     * @return array{name:string,arg:string,start:bool}
     */
    private function parseSlashCommand(string $content): array {
        $clean = $this->stripMention($content);
        if (!preg_match('~^/([a-z]+)(?:\s+(.*))?$~iu', trim($clean), $m)) {
            return ['name' => '', 'arg' => '', 'start' => false];
        }
        $name = strtolower($m[1]);
        $allowed = ['help', 'summarize', 'status', 'stop', 'start'];
        if (!in_array($name, $allowed, true)) {
            return ['name' => '', 'arg' => '', 'start' => false];
        }
        return ['name' => $name, 'arg' => trim((string)($m[2] ?? '')), 'start' => $name === 'start'];
    }

    private function handleSlashCommand(BotInvokeEvent $event, array $command, string $userId, int $roomId): void {
        switch ($command['name']) {
            case 'help':
                $event->addAnswer(
                    "These are my commands:\n"
                    . "- @Eva /help - this help\n"
                    . "- @Eva /summarize - summarize the recent messages in this room\n"
                    . "- @Eva /status - index and model status\n"
                    . "- @Eva /stop - pause me for this room\n"
                    . "- @Eva /start - activate me again for this room\n\n"
                    . "You can also simply mention @Eva and ask your question."
                );
                return;
            case 'stop':
                $this->roomState->setEnabled($roomId, false);
                $event->addAnswer('Okay, I am paused for this room. Say @Eva /start to activate me again.');
                return;
            case 'start':
                $this->roomState->setEnabled($roomId, true);
                $event->addAnswer('I am active in this room again!');
                return;
            case 'status':
                $status = $this->ragService->buildStatus($userId);
                $lines = [];
                $lines[] = 'Ollama: ' . ((bool)($status['ollamaOnline'] ?? false) ? '✅ online' : '❌ offline');
                $lines[] = 'Chat-Modell: ' . ($status['chatModel'] ?? '') . ((bool)($status['chatModelInstalled'] ?? false) ? ' ✅' : ' ⚠️');
                $lines[] = 'Embedding-Modell: ' . ($status['embeddingModel'] ?? '') . ((bool)($status['embeddingModelInstalled'] ?? false) ? ' ✅' : ' ⚠️');
                $lines[] = 'Dokumente im Index: ' . (int)($status['documents'] ?? 0);
                $lines[] = 'Index läuft gerade: ' . ((bool)($status['indexing'] ?? false) ? 'ja' : 'nein');
                $event->addAnswer(implode("\n", $lines));
                return;
            case 'summarize':
                $summary = $this->summarizeRoom($roomId, $userId);
                $event->addAnswer($summary);
                return;
        }
    }

    /**
     * Summarize the recent room messages deterministically (Issue #85):
     * reuses the Talk history reader for the last N messages and asks the
     * configured chat model to condense them. Falls back to a clear message
     * when there is nothing to summarize or the model is unreachable.
     */
    private function summarizeRoom(int $roomId, string $userId): string {
        $history = $roomId > 0 ? $this->contextReader->buildHistoryMessages($roomId) : [];
        // buildHistoryMessages returns role/content pairs; keep only user text
        // for the summary input and bound it like the regular chat history.
        $texts = [];
        foreach ($history as $h) {
            $c = trim((string)($h['content'] ?? ''));
            if ($c !== '' && ($h['role'] ?? '') === 'user') {
                $texts[] = $c;
            }
        }
        if ($texts === []) {
            return 'There are no messages in this room to summarize yet.';
        }
        $joined = mb_substr(implode("\n", array_slice($texts, -40)), 0, 12000);
        $messages = [
            ['role' => 'system', 'content' => 'You are EVA. Summarize the following chat messages in 3-6 sentences, in the language the messages are written in. Name the most important topics and results without inventing details.'],
            ['role' => 'user', 'content' => $joined],
        ];
        $resp = $this->ollama->chat($messages, []);
        if (isset($resp['error']) || trim((string)($resp['answer'] ?? '')) === '') {
            return 'The summary is not possible right now (the model is unreachable).';
        }
        return trim((string)$resp['answer']);
    }

    /** Prüft, ob EVA explizit per @Mention oder Custom-Trigger angesprochen wurde. */
    private function isExplicitlyMentioned(string $content): bool {
        $configured = $this->appConfig->get('talk_bot_trigger');
        return $configured !== '' && preg_match(
            '/(^|[^[:alnum:]_])@?' . preg_quote($configured, '/') . '([^[:alnum:]_]|$)/i',
            $content
        ) === 1;
        /* Legacy alias matching is intentionally unreachable. */
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
     * Entscheidet ob EVA antworten soll (Issue #77).
     *
     * Um jede Raum-Nachricht von einer teuren LLM-Klassifikation
     * fernzuhalten, entscheidet zuerst ein deterministischer Pre-Filter:
     * 1. @EVA/@eva/Custom-Trigger Erwähnung → immer antworten (explizite Adressierung)
     * 2. Mit talk_classify_all=1 kann der Admin die alte „jede Nachricht
     *    klassifizieren“-Logik wieder einschalten (Standard: aus).
     * 3. Sonst: nur Nachrichten, die den kostengünstigen Heuristik-Pre-Filter
     *    passieren (Triggerwort, Frageform, Bot-Name im Text), erreichen die
     *    KI-Klassifikation. Reiner Smalltalk zwischen Menschen löst also keine
     *    LLM-Anfrage aus und wird nie an ein Modell geschickt.
     */
    private function shouldRespond(string $content, string $currentUserId, int $roomId, bool $explicit = false): bool {
        return $explicit;
        /* Legacy classifier path retained below for compatibility documentation. */
        // 1. Explizite Adressierung – schneller Check, keine LLM-Anfrage nötig.
        if ($explicit) {
            return true;
        }

        $triggerName = $this->appConfig->get('talk_bot_trigger');

        // 2. Opt-in „jede Nachricht per KI klassifizieren“ (alter Modus).
        if ($this->appConfig->get('talk_classify_all') === '1') {
            return $this->classificationForEva($content, $roomId, $triggerName);
        }

        // 3. Kostengünstiger Pre-Filter: Nur plausibel an den Bot gerichtete
        // Nachrichten dürfen eine LLM-Klassifikation auslösen.
        if (!$this->heuristicPrefilter($content, $triggerName)) {
            return false;
        }
        return $this->classificationForEva($content, $roomId, $triggerName);
    }

    /**
     * Deterministischer, LLM-freier Pre-Filter (Issue #77).
     *
     * Gibt true zurück, wenn die Nachricht plausibel an den Bot gerichtet ist
     * – Bot-Name/Triggerwort im Text, Frageform, Imperativ/Aufforderung an den
     * Assistenten oder Hilfebegriffe. Reiner Smalltalk (kein Trigger, keine
     * Frage) wird hier abgefangen, damit keine Klassifikations-LLM-Anfrage
     * ausgelöst wird.
     */
    private function heuristicPrefilter(string $content, string $triggerName): bool {
        $text = mb_strtolower(trim($content));
        if ($text === '') {
            return false;
        }
        // Bot-Name/Triggerwort als eigenständiges Wort im Text (auch ohne @).
        $needles = ['eva'];
        if ($triggerName !== '' && strtolower($triggerName) !== 'eva') {
            $needles[] = strtolower($triggerName);
        }
        foreach ($needles as $needle) {
            if (preg_match('/(?:^|[^\p{L}\p{N}])' . preg_quote($needle, '/') . '(?:$|[^\p{L}\p{N}])/iu', $text)) {
                return true;
            }
        }
        // Fragezeichen (Frage an den Raum – meist an den Assistenten).
        if (str_contains($text, '?')) {
            return true;
        }
        // Typische Aufforderungen an einen Assistenten (deutsch und englisch,
        // damit der Bot die Sprache des Raums nicht voraussetzt).
        if (preg_match(
            '/(?:bitte|kannst du|könntest du|hilf mir|erklär|zusammenfass|erinner|termin|wetter|wie viel|was ist|wer ist'
            . '|please|can you|could you|help me|explain|summar|remind|schedule|appointment|weather|how much|how many|what is|what are|who is)/u',
            $text
        )) {
            return true;
        }
        return false;
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
            ['role' => 'system', 'content' => 'You are an AI assistant named "' . $triggerName . '". '
                . 'So your name is: ' . $triggerName . '. '
                . $participantInfo . ' '
                . 'ANSWER ONLY with "yes" or "no". '
                . 'Is this message meant for YOU (the AI assistant)? '
                . 'Yes when: a question is addressed to you, an action is expected from you, '
                . 'or the message is clearly directed at the AI. '
                . 'No when: the message is for another person, small talk between others, '
                . 'or a remark that is not directed at the AI. '
                . 'If a real person with the same name is in the chat and you are unsure, answer "no".'],
            ['role' => 'user', 'content' => $content],
        ];

        $resp = $this->ollama->chat($messages, []);
        if (isset($resp['error'])) {
            $this->logger->warning('eva_ai talk: classification error: ' . $resp['error']);
            return false; // Bei Fehler: nicht antworten (sicherer)
        }

        return $this->isAffirmative((string)($resp['answer'] ?? ''));
    }

    /**
     * Whether a classification answer means "yes".
     *
     * Both languages are accepted, because a small model answers in the language
     * of the chat rather than the language of the instruction, and a German "ja"
     * used to be read as a refusal.
     *
     * The word is matched, not the first two characters: models decorate the one
     * word they were asked for ("**Ja**", '"Yes"', "1. Yes, ..."), and comparing
     * the raw prefix silenced the bot for answers that plainly meant yes. The
     * leading decoration is therefore stripped first, and anything that is not a
     * clear affirmative - "nein", "no, that is for Bob", "maybe" - stays a no, so
     * the bot does not talk over people.
     */
    private function isAffirmative(string $answer): bool
    {
        $normalised = mb_strtolower(trim($answer));
        $normalised = (string)preg_replace('/^[^a-z\x{00e4}\x{00f6}\x{00fc}\x{00df}]+/u', '', $normalised);
        if ($normalised === '') {
            return false;
        }
        return preg_match('/^(?:ja|yes|yep|yup|jep|sure)\b/u', $normalised) === 1;
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
            $this->logger->warning('eva_ai talk: could not get participants: ' . $e->getMessage());
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
        $configured = $this->appConfig->get('talk_bot_trigger');
        if ($configured === '') {
            return trim($content);
        }
        return trim(preg_replace('/@?' . preg_quote($configured, '/') . '[\\s,:.\\-]*/iu', '', $content) ?? $content);
        /* Legacy alias stripping retained below for compatibility documentation. */
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
    private function generateAnswerWithRag(array $history, string $question, string $actorName, string $userId, int $roomId = 0): string {
        // Older messages of THIS room, when its history is indexed: the live
        // history above only covers the last few messages, so a question about
        // something said earlier is answered from the retrieved passages.
        $recall = $this->talkHistoryContext($userId, $roomId, $question);

        // RagService::ask() macht Vector-Search + Tool-Execution + LLM-Antwort
        $result = $this->ragService->ask($userId, $question, $history, null, null, null, $recall);

        if (isset($result['error']) && $result['error'] !== '') {
            $this->logger->warning('eva_ai talk: rag error: ' . $result['error']);
            // Bei Vektor-Fehler: fallback auf reinen LLM-Chat mit Tools
            return $this->fallbackAnswer($history, $question, $actorName, $userId);
        }

        $answer = trim((string)($result['answer'] ?? ''));

        // Sources als Fußnote anhängen, falls vorhanden
        $sources = $result['sources'] ?? [];
        if ($sources !== []) {
            $sourceRefs = [];
            foreach ($sources as $s) {
                $sourceRefs[] = (string)($s['name'] ?? $s['path'] ?? 'Source');
            }
            if ($sourceRefs !== []) {
                $answer .= "\n\n_Sources: " . implode(', ', $sourceRefs) . "_";
            }
        }

        return $answer;
    }

    /**
     * Indexed older passages of the room the question was asked in.
     *
     * Returns an empty string when Talk histories are not indexed for this user
     * or the room has no matching older messages, so the live history alone is
     * used - the answer never depends on the index being present.
     */
    private function talkHistoryContext(string $userId, int $roomId, string $question): string
    {
        if ($roomId <= 0) {
            return '';
        }
        try {
            $passages = $this->talkTranscripts->recall($userId, $roomId, $question, 4);
        } catch (\Throwable $e) {
            $this->logger->warning('eva_ai talk: history recall failed: ' . $e->getMessage());
            return '';
        }
        if ($passages === []) {
            return '';
        }
        return implode("\n---\n", $passages);
    }

    /**
     * Fallback: reiner LLM-Chat mit Tools, falls RAG fehlschlägt
     * (z.B. kein indexiertes Material vorhanden).
     *
     * @param list<array{role:string,content:string}> $history
     */
    private function fallbackAnswer(array $history, string $question, string $actorName, string $userId): string {
        $system = self::SYSTEM_PROMPT . "\nCurrent speaker: " . ($actorName !== '' ? $actorName : $userId);
        $messages = [
            ['role' => 'system', 'content' => $system],
        ];
        foreach ($history as $h) {
            $messages[] = $h;
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $this->executor->setUserId($userId);
        $tools = $this->executor->tools();

        for ($round = 0; $round < 3; $round++) {
            $chat = $this->ollama->chat($messages, $tools);
            if (isset($chat['error'])) {
                $this->logger->warning('eva_ai talk: fallback ollama error: ' . $chat['error']);
                return 'I currently have a connection problem to the AI.';
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
                return $answer !== '' ? $answer : 'Sorry, I did not understand that.';
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
