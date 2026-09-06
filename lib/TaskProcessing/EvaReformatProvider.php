<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextReformatParagraphs;
use RuntimeException;

class EvaReformatProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva_ai:text2text:reformat';
	}

	public function getName(): string {
		return $this->l->t('Eva (local)');
	}

	public function getTaskTypeId(): string {
		return TextToTextReformatParagraphs::ID;
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
			throw new RuntimeException('Kein Benutzerkontext');
		}
		$this->appConfig->setUserId($userId);

		$prompt = trim((string)($input['input'] ?? ''));
		if ($prompt === '') {
			throw new RuntimeException('Leere Eingabe');
		}

		$reportProgress(0.1);

		$messages = [
			['role' => 'system', 'content' => 'Du bist ein hilfreicher Assistent, der Texte formatiert. '
				. 'Formatiere den Text in saubere, logische Absätze. '
				. 'Trenne verschiedene Themen oder Gedanken in eigene Absätze. '
				. 'Behalte den Inhalt bei und ändere nur die Formatierung. '
				. 'Antworte in der gleichen Sprache wie der Originaltext. '
				. 'Gib nur den formatierten Text zurück, keine zusätzlichen Erklärungen.'],
			['role' => 'user', 'content' => 'Formatiere den folgenden Text in saubere Absätze:\n\n' . $prompt],
		];

		$reportProgress(0.3);

		$result = $this->ollama->chat($messages, [], 'summary');

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
