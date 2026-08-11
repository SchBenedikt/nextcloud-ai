<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AgentStore;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\Searcher;
use OCA\EvaAi\Service\TalkContextReader;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\ContextAgentInteraction;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provides conversations "core:contextagent:interaction" with a confirmation
 * mechanism:
 * - First interaction (confirmation = 0): EVA proposes actions, they are
 *   returned in the 'actions' output and NOT executed. The assistant UI shows
 *   a confirm/deny dialog.
 * - Confirmed interaction (confirmation = 1): the tools are executed and EVA
 *   confirms what has been done.
 */
class AgentInteractionProvider implements ISynchronousProvider {

	/**
	 * Tools that only read information. They are safe to execute during the
	 * proposal phase without user confirmation; their results are fed back to
	 * the model so it can propose a complete chain of modifying actions.
	 */
	private const READ_ONLY_TOOLS = [
		'list_files', 'read_file', 'search_files',
		'find_contact', 'read_profile',
		'list_calendars', 'list_calendar_events', 'find_free_slots',
		'search_mails', 'list_mails', 'read_mail', 'unread_mail_count',
		'list_shares', 'list_tasks', 'recent_activity', 'server_status',
		'current_time', 'weather',
	];

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private ActionExecutor $executor,
		private AgentStore $store,
		private IL10N $l,
		private LoggerInterface $logger,
		private TalkContextReader $talkContextReader,
		private Searcher $searcher,
		private IRootFolder $rootFolder,
	) {
	}

	public function getId(): string {
		return 'eva_ai:agent';
	}

	public function getName(): string {
		return $this->l->t('Eva');
	}

	public function getTaskTypeId(): string {
		return ContextAgentInteraction::ID;
	}

	public function getExpectedRuntime(): int {
		return 300;
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [
			'memories' => new ShapeDescriptor(
				$this->l->t('Memories'),
				$this->l->t('The memories to be injected into the chat prompt.'),
				EShapeType::ListOfTexts
			),
			'talk_room_ids' => new ShapeDescriptor(
				$this->l->t('Talk room IDs'),
				$this->l->t('List of Nextcloud Talk room IDs whose chat history should be included as context.'),
				EShapeType::ListOfTexts
			),
			'rag_enabled' => new ShapeDescriptor(
				$this->l->t('RAG enabled'),
				$this->l->t('Whether to perform vector retrieval from indexed files for knowledge augmentation.'),
				EShapeType::Text
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		// Set tool policy surface to TaskProcessing (per request, at execution time)
		$this->executor->setSurface(ToolPolicy::SURFACE_TASKPROCESSING);

		if ($userId === null) {
			throw new RuntimeException('Kein Benutzer kontext');
		}
		$prompt = trim((string)($input['input'] ?? ''));
		$confirmation = (int)($input['confirmation'] ?? 0);
		$token = (string)($input['conversation_token'] ?? '');
		$memories = is_array($input['memories'] ?? null) ? $input['memories'] : [];
		$talkRoomIds = is_array($input['talk_room_ids'] ?? null) ? $input['talk_room_ids'] : [];
		$ragEnabled = (bool)($input['rag_enabled'] ?? true);
		if ($token === '' || $token === '{}' || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $token)) {
			$token = 'eva-' . bin2hex(random_bytes(16));
		}

		// Bei einer Bestaetigung (confirmation=1) darf das Input leer sein:
		// Der Nutzer hat seinen Wunsch schon in der vorherigen Nachricht
		// geaeussert, der Provider fuehrt nur noch die vorgeschlagenen
		// Aktionen aus. Der Prompt wird aus dem Historien-Store gelesen.
		if ($prompt === '' && $confirmation !== 1) {
			throw new RuntimeException('Invalid input');
		}

		$state = $this->store->load($userId, $token);
		$history = $state['history'];
		$pending = $state['pending'];

		if ($confirmation === 1 && $prompt === '') {
			// Letzten bekannten User-Prompt aus der History wiederverwenden,
			// damit die Bestaetigungs-Task den Kontext behaelt.
			$promptFromHistory = '';
			foreach (array_reverse($history) as $h) {
				if (($h['role'] ?? '') === 'user' && ($h['content'] ?? '') !== '') {
					$promptFromHistory = (string)$h['content'];
					break;
				}
			}
			if ($promptFromHistory !== '') {
				$prompt = $promptFromHistory;
			}
		}

		$system = $this->buildPrompt($userId, $confirmation, $pending, $talkRoomIds, $ragEnabled);
		$messages = [['role' => 'system', 'content' => $system]];
		// Talk-Verlauf injizieren:
		// 1. Explizit über talk_room_ids (falls übergeben)
		// 2. Automatisch alle Talk-Räume des Users (falls Talk installiert)
		$talkHistory = $this->buildTalkHistoryContext($talkRoomIds, $userId);
		if (!empty($talkHistory)) {
			foreach ($talkHistory as $h) {
				$messages[] = $h;
			}
		}
		foreach (array_slice($history, -12) as $h) {
			if (isset($h['role'], $h['content'])) {
				$messages[] = $h;
			}
		}
		$messages[] = ['role' => 'user', 'content' => $prompt];

		// RAG: Vektor-Suche in indexierten Dateien - Kontext injizieren
		$tools = $this->executor->tools();
		if ($ragEnabled && $prompt !== '' && $this->searcher !== null) {
			$this->injectRagContext($messages, $userId, $prompt, $history);
		}

		if ($confirmation === 1) {
			return $this->runConfirmed($userId, $messages, $token, $history, $pending);
		}

		// Proposal phase: the LLM may freely call read-only information tools
		// (they are executed immediately and their results fed back), while
		// every modifying action is only collected and proposed for
		// confirmation. Multiple rounds are allowed so the model can first
		// gather facts (current_time, find_free_slots, ...) and then propose
		// the complete chain of actions.
		[$pendingNext, $answer] = $this->proposalPhase($userId, $messages, $tools);

		$output = $answer;
		if ($pendingNext !== []) {
			$names = array_map(static fn(array $c): string => (string)($c['name'] ?? '?'), $pendingNext);
			if ($answer === '') {
				$output = 'Ich möchte diese Aktionen ausführen: ' . implode(', ', $names) . '. Soll ich?';
			}
		}

		$actions = json_encode($pendingNext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->store->save($userId, $token, $history, $pendingNext);

		// Proposal: the 'actions' output carries the proposed actions for the
		// confirmation dialog. The Nextcloud assistant frontend expects
		// objects of the form {name, args} (args = argument object).
		$result = [
			'output' => $output,
			'conversation_token' => $token,
		];
		if ($pendingNext !== []) {
			$result['actions'] = $actions === false ? '' : $actions;
		} else {
			// 'actions' is part of the mandatory outputShape, so it must
			// always be present, but an empty string is "falsy": the
			// assistant listener stores null into agency_pending_actions and
			// the confirmation dialog stays hidden (a JSON array '[]' would
			// be truthy in the frontend and pop the dialog up again).
			$result['actions'] = '';
		}
		return $result;
	}

	/** @return array{0: array<int,array{name:string,args:array}>, 1: string} */
	private function proposalPhase(string $userId, array $messages, array $tools): array {
		$pending = [];
		$seen = [];
		$answer = '';
		for ($round = 0; $round < 3; $round++) {
			$chat = $this->ollama->chat($messages, $tools);
			if (isset($chat['error'])) {
				throw new RuntimeException((string)$chat['error']);
			}
			$answer = (string)($chat['answer'] ?? '');
			$calls = $this->normalize($chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? []);
			if ($calls === []) {
				$calls = $this->normalize($chat['tool_calls'] ?? []);
			}
			if ($calls === []) {
				break;
			}

			$ranInfo = false;
			foreach ($calls as $tc) {
				$name = (string)($tc['name'] ?? '');
				if ($name === '') {
					continue;
				}
				$args = is_array($tc['args'] ?? null) ? $tc['args'] : [];

				if (in_array($name, self::READ_ONLY_TOOLS, true)) {
					// Read-only: execute now, feed the result back to the model
					$ranInfo = true;
					try {
						$res = $this->executor->run($userId, $name, $args);
					} catch (\Throwable $e) {
						$res = ['ok' => false, 'error' => $e->getMessage()];
					}
					$messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => $this->canonical([$tc])];
					$messages[] = ['role' => 'tool', 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
				} else {
					// Modifying action: only propose, never execute
					$key = $name . '|' . json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					if (!isset($seen[$key])) {
						$seen[$key] = true;
						$pending[] = ['name' => $name, 'args' => $args];
					}
				}
			}
			if (!$ranInfo) {
				break;
			}
		}
		return [$pending, $answer];
	}

	/**
	 * Confirmation received: deterministically execute the confirmed actions,
	 * then let the LLM only write the final confirmation answer.
	 *
	 * The LLM never re-generates the tool calls here: local models are not
	 * reliable at re-emitting the exact same calls, which caused the
	 * "answer is simply regenerated" bug. Executing the stored $pending calls
	 * directly guarantees the user-confirmed actions really happen.
	 */
	private function runConfirmed(string $userId, array $messages, string $token, array $history, array $pending): array {
		// The user explicitly confirmed the proposed actions in the Assistant
		// UI - this is the same consent level as the web chat. Switch the
		// tool policy surface back to WEB so the confirmed mutating actions
		// (create_file, delete_file, calendar events, shares, ...) are allowed.
		$this->executor->setSurface(ToolPolicy::SURFACE_WEB);

		// Idempotency: if the user confirms again (double-click on the dialog,
		// or the assistant re-sends the confirmation) while nothing is pending
		// and an answer already exists in the history, simply say that
		// everything is done - no LLM call, no new tool proposal, no spam.
		if ($pending === [] && $this->historyHasAssistantAnswer($history)) {
			return [
				'output' => 'Alles erledigt – die bestätigten Aktionen habe ich bereits ausgeführt. Gibt es noch etwas, das ich für dich tun kann?',
				'conversation_token' => $token,
				// 'actions' is mandatory in the outputShape: empty string keeps
				// agency_pending_actions at null (no confirmation dialog)
				'actions' => '',
			];
		}

		$executed = [];
		foreach ($pending as $tc) {
			$name = (string)($tc['name'] ?? '');
			if ($name === '') {
				continue;
			}
			$args = is_array($tc['args'] ?? null) ? $tc['args'] : [];
			if ($args === [] && is_array($tc['arguments'] ?? null)) {
				// legacy store entries from earlier versions
				$args = $tc['arguments'];
			}
			try {
				$res = $this->executor->run($userId, $name, $args);
			} catch (\Throwable $e) {
				$res = ['ok' => false, 'error' => $e->getMessage()];
			}
			$executed[] = [
				'tool' => $name,
				'arguments' => $args,
				'ok' => (bool)($res['ok'] ?? false),
				'error' => $res['error'] ?? null,
				'result' => $res['result'] ?? null,
			];
		}

		// The confirmed actions are done. Give the LLM the real results and
		// let it write the final summary without tools (so it cannot invent
		// or repeat actions).
		$finalMessages = $messages;
		if ($executed !== []) {
			$resultsText = json_encode($executed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
			$finalMessages[] = [
				'role' => 'assistant',
				'content' => 'The user confirmed the actions below and I already executed them:\n' . $resultsText,
			];
			$finalMessages[] = [
				'role' => 'user',
				'content' => 'Please confirm in a short, natural answer (in the user\'s language) what has been done. If an action failed, say so honestly and suggest what to do next.',
			];
		} else {
			// nothing was pending (e.g. token mismatch): answer like a normal chat
			$finalMessages[] = [
				'role' => 'user',
				'content' => 'Please answer the request now.',
			];
		}

		$chat = $this->ollama->chat($finalMessages, []);
		if (isset($chat['error'])) {
			throw new RuntimeException((string)$chat['error']);
		}
		$answer = (string)($chat['answer'] ?? '');
		if (trim($answer) === '') {
			$answer = 'Ich habe die bestätigten Aktionen ausgeführt.';
		}

		$newHistory = array_merge($history, [
			['role' => 'assistant', 'content' => $answer],
		]);
		$this->store->save($userId, $token, $newHistory, []);

		// 'actions' is mandatory in the outputShape; empty string keeps
		// agency_pending_actions at null (no confirmation dialog).
		return [
			'output' => $answer,
			'conversation_token' => $token,
			'actions' => '',
		];
	}

	private function historyHasAssistantAnswer(array $history): bool {
		foreach (array_reverse($history) as $h) {
			if (($h['role'] ?? '') === 'assistant' && trim((string)($h['content'] ?? '')) !== '') {
				return true;
			}
		}
		return false;
	}

	/**
	 * Baut Talk-Verlauf auf. Nutzt explizit übergebene Room-IDs oder
	 * automatisch alle Talk-Räume des Users (wenn Talk installiert ist).
	 *
	 * @param list<string|int> $talkRoomIds Explizit übergebene Room-IDs
	 * @param string $userId Benutzer für automatische Raum-Erkennung
	 * @return list<array{role:string,content:string}>
	 */
	private function buildTalkHistoryContext(array $talkRoomIds, string $userId): array {
		// Falls explizit Room-IDs übergeben wurden, nur diese verwenden
		$rooms = $talkRoomIds;
		if (empty($rooms) && class_exists('\\OCA\\Talk\\Manager')) {
			// Automatisch alle Talk-Räume des Users finden
			try {
				$talkManager = \OC::$server->get(\OCA\Talk\Manager::class);
				if ($talkManager !== null) {
					$rooms = array_map(static fn($r): int => (int)$r->getId(), $talkManager->getRoomsForUser($userId, false, false));
				}
			} catch (\Throwable $e) {
				// Talk nicht verfügbar oder Fehler - ignorieren
				$rooms = [];
			}
		}

		$context = [];
		foreach ($rooms as $roomIdRaw) {
			$roomId = (int)$roomIdRaw;
			if ($roomId <= 0) {
				continue;
			}
			try {
				$talkHistory = $this->talkContextReader->buildHistoryMessages($roomId);
			} catch (\Throwable $e) {
				continue;
			}
			if ($talkHistory === []) {
				continue;
			}
			$header = "Talk conversation (Room #" . $roomId . "):\n";
			$context[] = [
				'role' => 'user',
				'content' => $header . implode("\n", array_map(static fn($h): string => '[' . ($h['role'] === 'assistant' ? 'EVA' : 'User') . '] ' . $h['content'], $talkHistory)),
			];
		}
		return $context;
	}

	/**
	 * Führt RAG-Vektor-Suche durch und injiziert die gefundenen Snippets
	 * als Context in die letzte User-Nachricht.
	 *
	 * @param array $messages Referenz auf die Nachrichtenliste
	 * @param string $userId
	 * @param string $message Die aktuelle Nutzer-Nachricht
	 */
	private function injectRagContext(array &$messages, string $userId, string $message): void {
		try {
			// Prüfe ob Dokumente für diesen User indexiert sind
			$docCount = $this->appConfig->get('last_index_total');
			if ((int)$docCount === 0) {
				return; // nichts indexiert, nichts zu suchen
			}

			$topK = min($this->appConfig->getInt('top_k', 6), 8);
			$results = $this->searcher->search($userId, $message, $topK);

			if (empty($results)) {
				return;
			}

			// Kontext aus Ergebnissen bauen
			$context = '';
			$sourceRefs = [];
			foreach ($results as $i => $r) {
				$idx = $i + 1;
				$context .= "[{$idx}] (Source: {$r['docPath']})\n{$r['content']}\n\n";
				$sourceRefs[] = (string)($r['docName'] ?? $r['docPath'] ?? 'Source ' . $idx);
			}

			// Ersetze die letzte User-Nachricht durch eine erweiterte Version
			// mit RAG-Kontext
			$lastIndex = array_key_last($messages);
			if ($lastIndex !== null && ($messages[$lastIndex]['role'] ?? '') === 'user') {
				$sourceFooter = "\n\n_Relevant sources: " . implode(', ', array_slice($sourceRefs, 0, 10)) . "_";
				$messages[$lastIndex]['content'] = "Context from the user's indexed files:\n\n"
					. $context
					. "\n\nUser question: " . $messages[$lastIndex]['content']
					. $sourceFooter;
			}
		} catch (\Throwable $e) {
			// RAG ist optional - bei Fehler einfach weitermachen ohne Context
		}
	}

	private function buildPromptPrefix(): string {
		return "You are EVA, a helpful, precise assistant built into this Nextcloud instance. "
			. "You can act on the user's Nextcloud account via the provided tools "
			. "(files, notes, contacts, calendar, mail, shares, tasks, activity and more). "
			. "Always answer in the same language as the user's question.";
	}

	private function buildPrompt(string $userId, int $confirmation, array $pending, array $talkRoomIds = [], bool $ragEnabled = true): string {
		$knowledge = '';
		// KNOWLEDGE.md: auch im TaskProcessing-Worker (CLI) laden. Dazu wird
		// der User-File-Mount mit der unterstuetzten Nextcloud-API initialisiert
		// (Issue #10); schlaegt das fehl, wird der optionale Inhalt ignoriert.
		try {
			if (PHP_SAPI === 'cli') {
				\OC_Util::setupFS($userId);
			}
			$home = $this->rootFolder->getUserFolder($userId);
			if ($home->nodeExists('KNOWLEDGE.md')) {
				$knowledge = "\nA file KNOWLEDGE.md holds personal facts about the user. Always take them into account:\n\n"
					. substr((string)$home->get('KNOWLEDGE.md')->getContent(), 0, 2500);
			}
		} catch (\Throwable $e) {
			// ignore, knowledge is optional
		}

		$talkInfo = '';
		if (!empty($talkRoomIds)) {
			$talkInfo = "\n\nYou have access to the chat history of one or more Nextcloud Talk conversations. Relevant excerpts from these conversations are included in the conversation context above. When the user asks about something that was discussed in those rooms (e.g., \"what did Vinzent say?\", \"did we schedule the meeting?\"), answer based on those messages.";
		}

		$ragInfo = '';
		if ($ragEnabled) {
			$ragInfo = "\n\nYou also have access to the user's indexed files via RAG (vector search). Relevant snippets from their files are injected as context. Cite them with [1], [2], etc. if you use them. If no file context is available, just answer from your own knowledge.";
		}

		$base = $this->buildPromptPrefix() . $knowledge . $talkInfo . $ragInfo;

		if ($confirmation === 1) {
			$extra = '';
			if ($pending !== []) {
				$names = array_map(static fn(array $a): string => (string)($a['name'] ?? '?'), $pending);
				$extra = "\nEarlier you proposed these actions and the user confirmed them: "
					. implode(', ', $names)
					. ". They have already been executed; summarize the outcome instead of repeating them.";
			} else {
				$extra = "\nThe user has approved your plan: summarize what has been done.";
			}
			return $base . ' ' . $extra;
		}

		return $base . " Important safety rule: if the user asks you to create, modify, delete, move or rename "
			. "anything (e.g. files, calendar events, contacts, shares, tasks) or to send any message, you must NOT "
			. "execute the tools right away. Instead, make the tool calls you would run (with realistic arguments) "
			. "and write an answer that explains what you would do and asks for confirmation, for example: "
			. "'Ich würde den Termin verschieben, die Datei umbenennen und Benedikt eine Nachricht schicken – ausführen?' "
			. "Make ALL tool calls needed to fully complete the request in this single response, including preparatory steps that later calls depend on (for example current_time to know today's date before scheduling, or find_free_slots before proposing a meeting time). The user can only confirm the calls you propose now, so an incomplete chain means the job is never finished. "
			. "Only purely informational reads (searching, listing, reading, checking status) that are needed for the "
			. "answer may be executed directly; but never a modifying action. "
			. "Do NOT propose tools for simple conversation or greetings: if the user just says hello, asks a general "
			. "question or chats casually, answer directly without any tool calls and without asking for confirmation.";
	}

	private function canonical(array $raw): array {
		$out = [];
		foreach ($this->normalize($raw) as $tc) {
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

	private function normalize(array $raw): array {
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
			// the assistant frontend renders dialog actions as {name, args}
			$out[] = ['name' => $name, 'args' => $args];
		}
		return $out;
	}
}