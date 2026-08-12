<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\ToolPolicy;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;

/**
 * Stellt den RAG-Chat als TaskProcessing-Provider für die Nextcloud-Assistant-App bereit.
 */
class TextToTextChatProvider implements ISynchronousProvider {
	public function __construct(
		private RagService $ragService,
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
		return [];
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
		// The plain RAG chat mirrors the web chat UX (direct user chat, no
		// separate confirmation flow) - keep the full tool surface (WEB).
		$this->ragService->setSurface(ToolPolicy::SURFACE_WEB);

		if ($userId === null) {
			throw new ProcessingException('Not logged in');
		}
		$prompt = (string)($input['input'] ?? '');
		if (trim($prompt) === '') {
			throw new ProcessingException('Leere Eingabe');
		}
		$reportProgress(0.1);

		$history = [];
		$rawHistory = $input['history'] ?? [];
		if (is_array($rawHistory)) {
			foreach ($rawHistory as $i => $entry) {
				if (!is_string($entry)) {
					continue;
				}
				$history[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => $entry];
			}
		}

		$result = $this->ragService->ask($userId, $prompt, $history);
		$reportProgress(0.9);

		if (!empty($result['error'])) {
			throw new ProcessingException((string)$result['error']);
		}
		return ['output' => (string)($result['answer'] ?? '')];
	}
}
