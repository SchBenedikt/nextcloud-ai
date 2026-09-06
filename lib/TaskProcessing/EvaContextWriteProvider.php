<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\ContextWrite;
use RuntimeException;

class EvaContextWriteProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva_ai:contextwrite';
	}

	public function getName(): string {
		return $this->l->t('Eva (local)');
	}

	public function getTaskTypeId(): string {
		return ContextWrite::ID;
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
		return [
			'style' => new ShapeDescriptor(
				$this->l->t('Style'),
				$this->l->t('The desired writing style or context.'),
				EShapeType::Text
			),
			'example' => new ShapeDescriptor(
				$this->l->t('Example text'),
				$this->l->t('An example text to use as reference for the style.'),
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
		if ($userId === null) {
			throw new RuntimeException('No user context');
		}
		$this->appConfig->setUserId($userId);

		// ContextWrite's core task type uses source_input/style_input. Keep the
		// older aliases as fallbacks for already queued tasks.
		$prompt = trim((string)($input['source_input'] ?? $input['input'] ?? ''));
		if ($prompt === '') {
			throw new RuntimeException('Empty input');
		}

		$style = trim((string)($input['style_input'] ?? $input['style'] ?? ''));
		$example = trim((string)($input['example'] ?? ''));

		$reportProgress(0.1);

		$systemPrompt = 'You are an experienced writer who writes text in different styles. '
			. 'Write text based on the following instructions. '
			. 'Answer in the same language as the instructions. '
			. 'Return only the written text, without additional explanations.';

		if ($style !== '') {
			$systemPrompt .= ' Style: ' . $style;
		}

		$userMessage = $prompt;
		if ($example !== '') {
			$userMessage .= '\n\nExample text as a style reference:\n' . $example;
		}

		$messages = [
			['role' => 'system', 'content' => $systemPrompt],
			['role' => 'user', 'content' => $userMessage],
		];

		$reportProgress(0.3);

		$result = $this->ollama->chat($messages);

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
