<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\ToolPolicy;
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
		return [];
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
		$reportProgress(0.1);

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

		$result = $this->ragService->ask(new \OCA\EvaAi\Dto\ChatRequest(
			$userId,
			$prompt,
			$history,
			instructions: $instructions,
			memories: $memories,
		));
		$reportProgress(0.9);

		if (!empty($result['error'])) {
			throw new ProcessingException((string)$result['error']);
		}
		return ['output' => (string)($result['answer'] ?? '')];
	}
}
