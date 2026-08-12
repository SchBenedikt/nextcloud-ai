<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provider for "core:text2text:chatwithtools": a single chat round with the
 * given system prompt, history and tools. Tool call instructions from the
 * model are returned (the caller executes them and can feed the results back
 * via the 'tool_message' input in a follow-up task).
 */
class TextToTextChatWithToolsProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private ActionExecutor $executor,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'eva_ai:chatwithtools';
	}

	public function getName(): string {
		return $this->l->t('Eva');
	}

	public function getTaskTypeId(): string {
		return TextToTextChatWithTools::ID;
	}

	public function getExpectedRuntime(): int {
		return 120;
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [];
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
			throw new RuntimeException('Kein Benutzerkontext');
			$this->appConfig->setUserId($userId);
		}
		$chatInput = trim((string)($input['input'] ?? ''));
		if ($chatInput === '') {
			throw new RuntimeException('Invalid input');
		}

		$system = (string)($input['system_prompt'] ?? '');
		if ($system === '') {
			$system = 'You are EVA, a helpful, precise assistant built into this Nextcloud instance. '
				. 'Answer in the same language as the user, use the provided tools when needed.';
		}

		$messages = [];
		if ($system !== '') {
			$messages[] = ['role' => 'system', 'content' => $system];
		}
		foreach ((array)($input['history'] ?? []) as $entry) {
			if (!is_string($entry)) {
				continue;
			}
			$decoded = json_decode($entry, true);
			if (is_array($decoded) && isset($decoded['role'], $decoded['content'])) {
				$messages[] = ['role' => $decoded['role'], 'content' => (string)$decoded['content']];
			}
		}
		$toolMessage = trim((string)($input['tool_message'] ?? ''));
		if ($toolMessage !== '') {
			$messages[] = ['role' => 'user', 'content' => $toolMessage];
		}
		$messages[] = ['role' => 'user', 'content' => $chatInput];

		$tools = [];
		$rawTools = (string)($input['tools'] ?? '');
		if ($rawTools !== '') {
			$decoded = json_decode($rawTools, true);
			if (is_array($decoded)) {
				$tools = $decoded;
			}
		}

		$chat = $this->ollama->chat($messages, $tools);
		if (isset($chat['error'])) {
			throw new RuntimeException((string)$chat['error']);
		}

		$toolCalls = json_encode($this->externalToolCalls($chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return [
			'output' => (string)($chat['answer'] ?? ''),
			'tool_calls' => $toolCalls === false ? '[]' : $toolCalls,
		];
	}

	/**
	 * Serialize tool calls in the OpenAI-compatible JSON string format
	 * described by the task type (function name + JSON-string arguments).
	 */
	private function externalToolCalls(array $raw): array {
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
			if (is_array($args)) {
				$args = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
			$out[] = [
				'id' => (string)($tc['id'] ?? ('call_' . bin2hex(random_bytes(4)))),
				'type' => 'function',
				'function' => [
					'name' => $name,
					'arguments' => is_string($args) ? $args : '{}',
				],
			];
		}
		return $out;
	}
}