<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\ContextWrite;
use RuntimeException;

class EvaContextWriteProvider implements ISynchronousProvider {

	public function __construct(
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
			throw new RuntimeException('Kein Benutzerkontext');
		}

		// ContextWrite's core task type uses source_input/style_input. Keep the
		// older aliases as fallbacks for already queued tasks.
		$prompt = trim((string)($input['source_input'] ?? $input['input'] ?? ''));
		if ($prompt === '') {
			throw new RuntimeException('Leere Eingabe');
		}

		$style = trim((string)($input['style_input'] ?? $input['style'] ?? ''));
		$example = trim((string)($input['example'] ?? ''));

		$reportProgress(0.1);

		$systemPrompt = 'Du bist ein erfahrener Schriftsteller, der Texte in verschiedenen Stilen schreibt. '
			. 'Schreibe einen Text basierend auf den folgenden Anweisungen. '
			. 'Antworte in der gleichen Sprache wie die Anweisungen. '
			. 'Gib nur den geschriebenen Text zurück, keine zusätzlichen Erklärungen.';

		if ($style !== '') {
			$systemPrompt .= ' Stil: ' . $style;
		}

		$userMessage = $prompt;
		if ($example !== '') {
			$userMessage .= '\n\nBeispiel-Text als Stilreferenz:\n' . $example;
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
			throw new RuntimeException('Leere Antwort vom Modell');
		}

		return ['output' => $answer];
	}
}
