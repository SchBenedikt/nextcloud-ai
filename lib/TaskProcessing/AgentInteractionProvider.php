<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AgentStore;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
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

	private const MAX_TOOL_ROUNDS = 6;

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private ActionExecutor $executor,
		private AgentStore $store,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'eva-ai:agent';
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
		if ($userId === null) {
			throw new RuntimeException('Kein Benutzer kontext');
		}
		$prompt = trim((string)($input['input'] ?? ''));
		$confirmation = (int)($input['confirmation'] ?? 0);
		$token = (string)($input['conversation_token'] ?? '');
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

		$system = $this->buildPrompt($userId, $confirmation, $pending);
		$messages = [['role' => 'system', 'content' => $system]];
		foreach (array_slice($history, -12) as $h) {
			if (isset($h['role'], $h['content'])) {
				$messages[] = $h;
			}
		}
		$messages[] = ['role' => 'user', 'content' => $prompt];

		$tools = $this->executor->tools();

		if ($confirmation === 1) {
			return $this->runConfirmed($userId, $messages, $tools, $token, $history);
		}

		// Proposal phase: LLM with tools, intercept tool calls, do NOT execute
		$chat = $this->ollama->chat($messages, $tools);
		if (isset($chat['error'])) {
			throw new RuntimeException((string)$chat['error']);
		}
		$answer = (string)($chat['answer'] ?? '');
		$pendingNext = $this->normalize($chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? []);
		if ($pendingNext === []) {
			$pendingNext = $this->normalize($chat['tool_calls'] ?? []);
		}

		$output = $answer;
		if ($pendingNext !== []) {
			if ($answer === '') {
				$names = array_map(static fn(array $c): string => (string)($c['name'] ?? '?'), $pendingNext);
				$output = 'Ich möchte diese Aktionen ausführen: ' . implode(', ', $names) . '. Soll ich?';
			}
		}

		$actions = json_encode($pendingNext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->store->save($userId, $token, $history, $pendingNext);

		return [
			'output' => $output,
			'conversation_token' => $token,
			'actions' => $actions === false ? '[]' : $actions,
		];
	}

	/**
	 * Confirmation received: run the tools and deliver the final answer.
	 */
	private function runConfirmed(string $userId, array $messages, array $tools, string $token, array $history): array {
		$chat = $this->ollama->chat($messages, $tools);
		if (isset($chat['error'])) {
			throw new RuntimeException((string)$chat['error']);
		}

		for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
			$calls = $this->normalize($chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? []);
			$answer = (string)($chat['answer'] ?? '');

			if ($calls === []) {
				$newHistory = array_merge($history, [['role' => 'assistant', 'content' => $answer]]);
				$this->store->save($userId, $token, $newHistory, []);
				return [
					'output' => $answer,
					'conversation_token' => $token,
					'actions' => '[]',
				];
			}

			// replay the raw assistant message so the model's tool calls are preserved
			$messages[] = ['role' => 'assistant', 'content' => $answer, 'tool_calls' => $this->canonical($chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? [])];
			foreach ($calls as $tc) {
				try {
					$res = $this->executor->run($userId, (string)$tc['name'], is_array($tc['arguments']) ? $tc['arguments'] : []);
				} catch (\Throwable $e) {
					$res = ['ok' => false, 'error' => $e->getMessage()];
				}
				$messages[] = ['role' => 'tool', 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
			}

			$chat = $this->ollama->chat($messages, $tools);
			if (isset($chat['error'])) {
				throw new RuntimeException((string)$chat['error']);
			}
		}

		$this->store->save($userId, $token, $history, []);
		return [
			'output' => 'Zu viele Tool-Schritte – bitte erneut versuchen.',
			'conversation_token' => $token,
			'actions' => '[]',
		];
	}

	private function buildPromptPrefix(): string {
		return "You are EVA, a helpful, precise assistant built into this Nextcloud instance. "
			. "You can act on the user's Nextcloud account via the provided tools "
			. "(files, notes, contacts, calendar, mail, shares, tasks, activity and more). "
			. "Always answer in the same language as the user's question.";
	}

	private function buildPrompt(string $userId, int $confirmation, array $pending): string {
		$knowledge = '';
		// KNOWLEDGE.md: nur im Web-Kontext laden. Der TaskProcessing-Worker
		// laeuft als CLI und hat keinen eingerichteten User-File-Mount;
		// getUserFolder() blockiert dort (bekanntes Verhalten). Der
		// Wissens-Inhalt ist optional und darf den Worker nicht aufhaengen.
		if (PHP_SAPI !== 'cli') {
			try {
				$home = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($userId);
				if ($home->nodeExists('KNOWLEDGE.md')) {
					$knowledge = "\nA file KNOWLEDGE.md holds personal facts about the user. Always take them into account:\n\n"
						. substr((string)$home->get('KNOWLEDGE.md')->getContent(), 0, 2500);
				}
			} catch (\Throwable $e) {
				// ignore, knowledge is optional
			}
		}

		$base = $this->buildPromptPrefix() . $knowledge;

		if ($confirmation === 1) {
			$extra = '';
			if ($pending !== []) {
				$names = array_map(static fn(array $a): string => (string)($a['name'] ?? '?'), $pending);
				$extra = "\nEarlier you proposed these actions and the user confirmed them: "
					. implode(', ', $names)
					. ". Execute them now together with anything else the user asks for, then confirm what you did.";
			} else {
				$extra = "\nThe user has approved your plan: execute the requested actions now and confirm what you did.";
			}
			return $base . ' ' . $extra;
		}

		return $base . " Important safety rule: if the user asks you to create, modify, delete, move or rename "
			. "anything (e.g. files, calendar events, contacts, shares, tasks) or to send any message, you must NOT "
			. "execute the tools right away. Instead, make the tool calls you would run (with realistic arguments) "
			. "and write an answer that explains what you would do and asks for confirmation, for example: "
			. "'Ich würde den Termin verschieben, die Datei umbenennen und Benedikt eine Nachricht schicken – ausführen?' "
			. "Only purely informational reads (searching, listing, reading, checking status) that are needed for the "
			. "answer may be executed directly; but never a modifying action. "
			. "Do NOT propose tools for simple conversation or greetings: if the user just says hello, asks a general "
			. "question or chats casually, answer directly without any tool calls and without asking for confirmation.";
	}

	private function canonical(array $raw): array {
		$out = [];
		foreach ($this->normalize($raw) as $tc) {
			$obj = new \stdClass();
			$args = $tc['arguments'] ?? [];
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
			$args = $fn['arguments'] ?? [];
			if (is_string($args)) {
				$decoded = json_decode($args, true);
				$args = is_array($decoded) ? $decoded : [];
			}
			if (!is_array($args)) {
				$args = [];
			}
			$out[] = ['name' => $name, 'arguments' => $args];
		}
		return $out;
	}
}