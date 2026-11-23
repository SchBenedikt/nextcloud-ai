<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\ToolPolicy;
use OCA\EvaAi\Dto\ChatRequest;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;

/**
 * Stellt den RAG-Chat als TaskProcessing-Provider für die Nextcloud-Assistant-App bereit.
 */
class TextToTextChatProvider implements ISynchronousProvider {
	public function __construct(
		private RagService $ragService,
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'eva_ai:text2text';
	}

	#[\Override]
	public function getName(): string {
		return 'Eva · RAG';
	}

	#[\Override]
	public function getTaskTypeId(): string {
		return TextToTextChat::ID;
	}

	#[\Override]
	public function getExpectedRuntime(): int {
		return 120;
	}

	#[\Override]
	public function getOptionalInputShape(): array {
		return [
			'memories' => new ShapeDescriptor(
				$this->l->t('Memories'),
				$this->l->t('The memories to be injected into the chat prompt.'),
				EShapeType::ListOfTexts,
			),
		];
	}

	#[\Override]
	public function getOptionalOutputShape(): array {
		return [
			'sources' => new ShapeDescriptor(
				$this->l->t('Sources'),
				$this->l->t('Names of the EVA knowledge sources used to answer the chat message.'),
				EShapeType::ListOfTexts,
			),
		];
	}

	#[\Override]
	public function getInputShapeEnumValues(): array {
		return [];
	}

	#[\Override]
	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	#[\Override]
	public function getInputShapeDefaults(): array {
		return [];
	}

	#[\Override]
	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	#[\Override]
	public function getOutputShapeEnumValues(): array {
		return [];
	}

	#[\Override]
	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	#[\Override]
	public function process(?string $userId, array $input, callable $reportProgress): array {
		return $this->processWithIntermediateOutput($userId, $input, $reportProgress);
	}

	/**
	 * Shared implementation for the compatible synchronous and newer streaming
	 * provider contracts.
	 *
	 * @param null|callable(array{output:string}):bool $reportIntermediateOutput
	 */
	public function processWithIntermediateOutput(
		?string $userId,
		array $input,
		callable $reportProgress,
		?callable $reportIntermediateOutput = null,
		bool $preferStreaming = false,
	): array {
		// Assistant tasks must never expose or execute mutating web tools.
		// Read-only tools remain available through the RAG service.
		$this->ragService->setSurface(ToolPolicy::SURFACE_TASKPROCESSING);

		if ($userId === null) {
			throw new ProcessingException('Not logged in');
		}
		$prompt = (string)($input['input'] ?? '');
		if (trim($prompt) === '') {
			throw new ProcessingException('Empty input');
		}
		TaskProcessingProgress::report($reportProgress, 0.1);

		$history = TaskProcessingHistory::parse($input['history'] ?? []);

		$instructions = trim((string)($input['system_prompt'] ?? ''));
		$instructions = $instructions === '' ? null : mb_substr($instructions, 0, 4000);
		$memories = [];
		$totalMemoryChars = 0;
		foreach (array_slice((array)($input['memories'] ?? []), 0, 10) as $memory) {
			if (!is_string($memory) || trim($memory) === '') {
				continue;
			}
			$memory = mb_substr(trim($memory), 0, 2000);
			if ($totalMemoryChars + mb_strlen($memory) > 12000) {
				break;
			}
			$memories[] = $memory;
			$totalMemoryChars += mb_strlen($memory);
		}

		$request = new ChatRequest(
			$userId,
			$prompt,
			$history,
			instructions: $instructions,
			memories: $memories,
		);
		if ($preferStreaming && $reportIntermediateOutput !== null) {
			$result = $this->askStreaming($request, $reportProgress, $reportIntermediateOutput);
		} else {
			$result = $this->ragService->ask($request);
		}
		TaskProcessingProgress::report($reportProgress, 0.9);

		if (!empty($result['error'])) {
			throw new ProcessingException((string)$result['error']);
		}
		return [
			'output' => (string)($result['answer'] ?? ''),
			'sources' => $this->normalizeSources($result['sources'] ?? []),
		];
	}

	/** Stream final chat text into TaskProcessing and preserve structured citations. */
	private function askStreaming(ChatRequest $request, callable $reportProgress, callable $reportIntermediateOutput): array {
		$answer = '';
		$result = null;
		$lastReportedAt = microtime(true);
		foreach ($this->ragService->askStream($request) as $line) {
			$event = json_decode(trim($line), true);
			if (!is_array($event)) {
				continue;
			}
			$type = $event['type'] ?? '';
			if ($type === 'content') {
				$answer .= (string)($event['delta'] ?? '');
				if (microtime(true) - $lastReportedAt >= 0.25) {
					TaskProcessingProgress::report($reportProgress, 0.6);
					if ($reportIntermediateOutput(['output' => $answer]) === false) {
						throw new TaskCancelledException('Task cancelled while streaming');
					}
					$lastReportedAt = microtime(true);
				}
			} elseif ($type === 'done') {
				$result = [
					'answer' => (string)($event['answer'] ?? $answer),
					'sources' => is_array($event['sources'] ?? null) ? $event['sources'] : [],
					'error' => null,
				];
			} elseif ($type === 'confirmation') {
				$result = [
					'answer' => 'I need your confirmation before I can perform that action.',
					'sources' => [],
					'error' => null,
				];
				break;
			} elseif ($type === 'error') {
				throw new ProcessingException((string)($event['message'] ?? $event['delta'] ?? 'The model could not complete the request.'));
			}
		}

		if ($result === null) {
			throw new ProcessingException('The model did not complete the response.');
		}
		$finalAnswer = (string)$result['answer'];
		if ($finalAnswer !== '') {
			TaskProcessingProgress::report($reportProgress, 0.8);
			if ($reportIntermediateOutput(['output' => $finalAnswer]) === false) {
				throw new TaskCancelledException('Task cancelled while streaming');
			}
		}
		return $result;
	}

	/** @return list<string> */
	private function normalizeSources(mixed $rawSources): array {
		$sources = [];
		foreach (array_slice((array)$rawSources, 0, 8) as $source) {
			if (!is_array($source)) {
				continue;
			}
			$name = trim((string)($source['name'] ?? $source['path'] ?? ''));
			if ($name === '') {
				continue;
			}
			$label = 'EVA: ' . mb_substr($name, 0, 180);
			if (!in_array($label, $sources, true)) {
				$sources[] = $label;
			}
		}

		return $sources;
	}
}
