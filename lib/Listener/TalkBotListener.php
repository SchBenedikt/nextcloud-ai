<?php

declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\TalkContextReader;
use OCA\EvaAi\Service\ToolPolicy;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Model\Bot;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * EVA bot for Nextcloud Talk with tool support and RAG.
 *
 * Listens for BotInvokeEvent (FEATURE_EVENT) and responds in the room with
 * an LLM answer. RagService provides:
 * - vector search in indexed files (RAG)
 * - read-only tools (calendar, tasks, files, contacts, etc.) in Talk;
 *   mutating and destructive tools are deliberately unavailable
 *
 * Selective response logic (no pattern-based filtering):
 * - @EVA/@eva mention → always respond (explicit addressing)
 * - custom trigger (configurable) → always respond
 * - otherwise, AI classification based on content and chat participants decides
 *
 * @implements IEventListener<Event>
 */
class TalkBotListener implements IEventListener {
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are EVA, a helpful AI assistant in a Nextcloud Talk conversation. Answer briefly and kindly (1–3 sentences) in the user's language.

You have access to read-only tools (calendar, tasks, files, contacts, mail, etc.). Use them when the user asks for information.

Important: In the Talk context you cannot create, change, or delete files, contacts, calendars, shares, or tasks. Explain this briefly and direct the user to the EVA web chat, where explicit confirmation is required.
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
        // Respond only to chat messages; ignore reactions and system messages.
        if (!isset($data['type']) || ($data['type'] !== 'Create' && $data['type'] !== 'Activity')) {
            return;
        }
        $content = trim((string)($data['object']['content'] ?? ''));
        if ($content === '') {
            return;
        }
        // Identify the asker so the response uses the speaker's user context.
        $actorName = (string)($data['actor']['name'] ?? '');
        $userId = $this->extractUserId($data['actor']['id'] ?? '');
        if ($userId === null) {
            $event->addAnswer("I can only answer when I know which user asked the question.");
            return;
        }

        $this->appConfig->setUserId($userId);

        // Selective response logic: answer only when addressed.
        $roomId = (int)($data['target']['id'] ?? 0);
        $explicit = $this->isExplicitlyMentioned($content);
        if (!$this->shouldRespond($content, $userId, $roomId, $explicit)) {
            return; // Stille – keine Antwort.
        }

        // Remove the speaker mention, if present.
        $cleanContent = $this->stripMention($content);

        // Load the recent chat history.
        $history = $roomId > 0 ? $this->contextReader->buildHistoryMessages($roomId) : [];

        try {
            $answer = $this->generateAnswerWithRag($history, $cleanContent, $actorName, $userId);
            if (trim($answer) === '') {
                // Post an empty-answer fallback only when EVA was explicitly addressed
                // (for example, "@Eva …"). Stay silent for a classified ambient
                // message to avoid adding unnecessary noise to the conversation.
                if (!$explicit) {
                    return;
                }
                $event->addAnswer("I could not find a suitable answer right now. Could you phrase the question differently?");
                return;
            }
            $event->addAnswer($answer);
        } catch (\Throwable $e) {
            $this->logger->error('eva_ai talk bot failed', ['exception' => $e]);
            $event->addAnswer("Something went wrong on my side. Please try again shortly.");
        }
    }

    /** Check whether EVA was explicitly addressed through an @mention or custom trigger. */
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
     * Decide whether EVA should respond.
     *
     * No pattern-based filtering. AI decides for every message:
     * 1. @EVA/@eva/custom trigger mention → always respond (explicit addressing)
     * 2. Otherwise: AI classification based on content and participants
     */
    private function shouldRespond(string $content, string $currentUserId, int $roomId, bool $explicit = false): bool {
        // 1. @EVA/@eva/custom trigger mention – fast check (explicit addressing)
        if ($explicit) {
            return true;
        }

        // Classifying ambient conversations requires a separate explicit opt-in.
        if ($this->appConfig->get('talk_classification_enabled') !== '1') {
            return false;
        }
        $triggerName = $this->appConfig->get('talk_bot_trigger');
        return $this->classificationForEva($content, $roomId, $triggerName);
    }

    /**
     * AI-based classification: is this message addressed to the EVA assistant?
     *
     * AI decides for EVERY message based on content and chat participants.
     * There is no pattern-based filtering.
     */
    private function classificationForEva(string $content, int $roomId, string $triggerName): bool {
        $participants = $this->getRoomParticipantNames($roomId);
        $participantInfo = $participants !== [] ? "\nChat participants: " . implode(', ', $participants) . "\n" : "\nNo participant information is available.\n";

        $messages = [
            ['role' => 'system', 'content' => 'You are an AI assistant named "' . $triggerName . '". '
                . 'Your name is: ' . $triggerName . '. '
                . $participantInfo . ' '
                . 'ANSWER ONLY with "yes" or "no". '
                . 'Is this message intended for YOU (the AI assistant)? '
                . 'Yes if: a question is addressed to you, an action is expected from you, '
                . 'or the message is clearly directed at the AI. '
                . 'No if: the message is addressed to another person, is small talk between others, '
                . 'or is a remark not directed at the AI. '
                . 'If a real person with the same name is in the chat and you are uncertain, answer "no".'],
            ['role' => 'user', 'content' => $content],
        ];

        $resp = $this->ollama->chat($messages, []);
        if (isset($resp['error'])) {
            $this->logger->warning('eva_ai talk: classification error: ' . $resp['error']);
            return false; // On error, do not answer (safer).
        }

        $answer = strtolower(trim((string)($resp['answer'] ?? '')));
        return str_starts_with($answer, 'yes');
    }

    /**
     * Get chat participant names for classification.
     *
     * @return list<string>
     */
    private function getRoomParticipantNames(int $roomId): array {
        if ($roomId <= 0) {
            return [];
        }

        try {
            // Use TalkManager to retrieve the room.
            $roomManager = \OC::$server->get(\OCA\Talk\Manager::class);
            $room = $roomManager->getRoomById($roomId);
            if ($room === null) {
                return [];
            }

            // Use ParticipantService to retrieve participants.
            $participantService = \OC::$server->get(\OCA\Talk\Service\ParticipantService::class);
            $participants = $participantService->getParticipantsForRoom($room);

            $names = [];
            foreach ($participants as $participant) {
                $actorType = $participant->getActorType();
                $actorId = $participant->getActorId();

                // Consider only users and guests.
                if ($actorType === 'users' || $actorType === 'guests') {
                    // For users, resolve the display name.
                    if ($actorType === 'users') {
                        $userManager = \OC::$server->get(\OCP\IUserManager::class);
                        $user = $userManager->get($actorId);
                        if ($user !== null) {
                            $names[] = $user->getDisplayName();
                        } else {
                            $names[] = $actorId;
                        }
                    } else {
                        // For guests, use the actor ID as the name.
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
     * Extract the Nextcloud user ID from an actor ID such as
     * "users/diag9" or "federated_users/...".
     */
    private function extractUserId(string $actorId): ?string {
        if (str_starts_with($actorId, 'users/')) {
            return substr($actorId, strlen('users/'));
        }
        // Do not execute tools for federated users or guests.
        return null;
    }

    /** Remove "@EVA" / "@eva" and custom trigger mentions from the text.
     *  Remove only names that include @, not the plain name,
     *  because it may refer to a real person.
     */
    private function stripMention(string $content): string {
        // Remove @EVA/@eva and @CustomTrigger (only with @).
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
     * Generate an answer with RAG (Retrieval-Augmented Generation) and
     * tool support, matching the EVA web app.
     *
     * RagService::ask() provides:
     * - vector search in indexed files/knowledge
     * - tool calls (calendar, tasks, files, etc.)
     * - LLM answer generation
     *
     * @param list<array{role:string,content:string}> $history
     */
    private function generateAnswerWithRag(array $history, string $question, string $actorName, string $userId): string {
        // RagService::ask() performs vector search, tool execution, and LLM answering.
        $result = $this->ragService->ask($userId, $question, $history);

        if (isset($result['error']) && $result['error'] !== '') {
            $this->logger->warning('eva_ai talk: rag error: ' . $result['error']);
            // On vector-search failure, fall back to a plain LLM chat with tools.
            return $this->fallbackAnswer($history, $question, $actorName, $userId);
        }

        $answer = trim((string)($result['answer'] ?? ''));

        // Append sources as a footnote when available.
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
     * Fallback: plain LLM chat with tools when RAG fails
     * (for example, when no material has been indexed).
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

        $tools = $this->executor->tools();

        for ($round = 0; $round < 3; $round++) {
            $chat = $this->ollama->chat($messages, $tools);
            if (isset($chat['error'])) {
                $this->logger->warning('eva_ai talk: fallback ollama error: ' . $chat['error']);
                return "I currently have a connection problem with the AI service.";
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
                return $answer !== '' ? $answer : "I could not understand that request.";
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
     * Convert tool calls to Ollama's canonical format.
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
