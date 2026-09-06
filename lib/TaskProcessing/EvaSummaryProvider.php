<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use RuntimeException;

class EvaSummaryProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva_ai:text2text:summary';
	}

	public function getName(): string {
		return $this->l->t('Eva · Local');
	}

	public function getTaskTypeId(): string {
		return TextToTextSummary::ID;
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
		if ($userId === null) {
			throw new RuntimeException('No user context');
		}
		$this->appConfig->setUserId($userId);

		$prompt = trim((string)($input['input'] ?? ''));
		if ($prompt === '') {
			throw new RuntimeException('Empty input');
		}

		$reportProgress(0.1);

		$messages = [
			['role' => 'system', 'content' => 'You are a helpful assistant that summarizes text. '
				. 'Summarize the text precisely and comprehensively. '
				. 'Answer in the same language as the original text. '
				. 'Return only the summary, without additional explanations.'],
			['role' => 'user', 'content' => 'Summarize the following text:\n\n' . $prompt],
		];

		$reportProgress(0.3);

		$result = $this->ollama->chat($messages, [], 'summary');

		if (isset($result['error'])) {
			throw new RuntimeException((string)$result['error']);
		}

		$reportProgress(0.9);

		$answer = (string)($result['answer'] ?? '');

		if (trim($answer) === '') {
			throw new RuntimeException('The model returned an empty answer');
		}

		return ['output' => $answer];
	}
}
