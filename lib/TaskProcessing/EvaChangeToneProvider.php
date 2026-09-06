<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextChangeTone;
use RuntimeException;

class EvaChangeToneProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva_ai:text2text:change-tone';
	}

	public function getName(): string {
		return $this->l->t('Eva (local)');
	}

	public function getTaskTypeId(): string {
		return TextToTextChangeTone::ID;
	}

	public function getExpectedRuntime(): int {
		return 120;
	}

	public function getInputShapeEnumValues(): array {
		return [
			'tone' => [
				new ShapeEnumValue($this->l->t('Formal'), 'formal'),
				new ShapeEnumValue($this->l->t('Informal'), 'informal'),
				new ShapeEnumValue($this->l->t('Friendly'), 'friendly'),
				new ShapeEnumValue($this->l->t('Professional'), 'professional'),
				new ShapeEnumValue($this->l->t('Humorous'), 'humorous'),
				new ShapeEnumValue($this->l->t('Persuasive'), 'persuasive'),
				new ShapeEnumValue($this->l->t('Concise'), 'concise'),
				new ShapeEnumValue($this->l->t('Detailed'), 'detailed'),
			],
		];
	}

	public function getInputShapeDefaults(): array {
		return [
			'tone' => 'formal',
		];
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

		$tone = (string)($input['tone'] ?? 'formal');

		$reportProgress(0.1);

		$toneDescriptions = [
			'formal' => 'formal and polite',
			'informal' => 'informal and relaxed',
			'friendly' => 'friendly and approachable',
			'professional' => 'professional and factual',
			'humorous' => 'humorous and relaxed',
			'persuasive' => 'persuasive and convincing',
			'concise' => 'concise and to the point',
			'detailed' => 'detailed and comprehensive',
		];

		$toneDescription = $toneDescriptions[$tone] ?? $tone;

		$messages = [
			['role' => 'system', 'content' => 'You are a helpful assistant that changes the tone of text. '
				. 'Rewrite the text in this tone: ' . $toneDescription . '. '
				. 'Preserve the content and change only the style and tone. '
				. 'Answer in the same language as the original text. '
				. 'Return only the rewritten text, without additional explanations.'],
			['role' => 'user', 'content' => 'Change the tone of the following text:\n\n' . $prompt],
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
