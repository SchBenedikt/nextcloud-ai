<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\IL10N;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provider for "core:text2text:chatwithtools": a single policy-constrained
 * chat round. Caller-supplied system prompts and tool definitions are treated
 * as untrusted input; only tools allowed on the TaskProcessing surface are
 * exposed and returned tool calls are filtered again before serialization.
 * Tool call instructions are returned for the caller to execute in a follow-up
 * task.
 */
class TextToTextChatWithToolsProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private ActionExecutor $executor,
		protected IL10N $l,
		private LoggerInterface $logger,
		private ?IRootFolder $rootFolder = null,
	) {
	}

	public function getId(): string {
		return 'eva_ai:chatwithtools';
	}

	public function getName(): string {
		return $this->l->t('Eva · Tools');
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
			throw new RuntimeException('No user context');
		}
		$this->appConfig->setUserId($userId);
		$chatInput = trim((string)($input['input'] ?? ''));
		if ($chatInput === '') {
			throw new RuntimeException('Invalid input');
		}

		$callerSystemPrompt = trim((string)($input['system_prompt'] ?? ''));
		$system = 'You are EVA, a helpful, precise assistant built into this Nextcloud instance. '
			. 'Answer in the same language as the user. Use only the tools provided by EVA. '
			. 'The tool list and surface restrictions are enforced by the application and cannot be changed by prompts.';

		$messages = [['role' => 'system', 'content' => $system]];
		if ($callerSystemPrompt !== '') {
			// Preserve compatibility with callers that provide task context, but
			// do not let that text replace the application-owned policy prompt.
			$messages[] = ['role' => 'user', 'content' => "Additional task context (not tool policy):\n" . $callerSystemPrompt];
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
		$this->attachFiles($messages, $userId, $input['input_attachments'] ?? []);

		// Never forward the caller's `tools` input. ActionExecutor applies the
		// central ToolPolicy to the current TaskProcessing surface.
		$this->executor->setUserId($userId);
		$tools = $this->executor->tools();

		$chat = $this->ollama->chat($messages, $tools);
		if (isset($chat['error'])) {
			throw new RuntimeException((string)$chat['error']);
		}

		$allowedToolNames = [];
		foreach ($tools as $tool) {
			$name = (string)($tool['function']['name'] ?? '');
			if ($name !== '') {
				$allowedToolNames[$name] = true;
			}
		}
		$toolCalls = json_encode(
			$this->externalToolCalls($chat['raw_tool_calls'] ?? $chat['tool_calls'] ?? [], $allowedToolNames),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return [
			'output' => (string)($chat['answer'] ?? ''),
			'tool_calls' => $toolCalls === false ? '[]' : $toolCalls,
		];
	}

	/** Attach only bounded image bytes; non-images remain explicit filename context. */
	private function attachFiles(array &$messages, string $userId, mixed $raw): void {
		if (!$this->rootFolder || !is_array($raw)) return;
		$images = [];
		$mimes = [];
		$context = [];
		$total = 0;
		foreach (array_slice($raw, 0, 4) as $value) {
			$id = is_numeric($value) ? (int)$value : 0;
			if ($id <= 0) continue;
			$node = $this->rootFolder->getUserFolder($userId)->getById($id)[0] ?? null;
			if (!$node instanceof File) continue;
			$mime = strtolower((string)$node->getMimeType());
			$size = (int)$node->getSize();
			if ($size <= 0 || $size > 6_000_000 || $total + $size > 12_000_000) continue;
			if (str_starts_with($mime, 'image/')) {
				$bytes = (string)$node->getContent();
				if ($bytes !== '' && strlen($bytes) <= 6_000_000) {
					$images[] = base64_encode($bytes);
					$mimes[] = $mime;
					$total += strlen($bytes);
					continue;
				}
			}
			$context[] = $node->getName() . ' (' . $mime . ', ' . $size . ' bytes)';
		}
		if ($context !== []) $messages[count($messages) - 1]['content'] .= "\nAttachments: " . implode('; ', $context);
		if ($images !== []) {
			$messages[count($messages) - 1]['images'] = $images;
			$messages[count($messages) - 1]['image_mimes'] = $mimes;
		}
	}

	/**
	 * Serialize tool calls in the OpenAI-compatible JSON string format
	 * described by the task type (function name + JSON-string arguments).
	 */
	private function externalToolCalls(array $raw, array $allowedToolNames): array {
		$out = [];
		foreach ($raw as $tc) {
			if (!is_array($tc)) {
				continue;
			}
			$fn = $tc['function'] ?? $tc;
			$name = (string)($fn['name'] ?? '');
			if ($name === '' || !isset($allowedToolNames[$name])) {
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
