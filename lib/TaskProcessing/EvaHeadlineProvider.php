<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextHeadline;
use RuntimeException;

class EvaHeadlineProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva_ai:text2text:headline';
	}

	public function getName(): string {
		return $this->l->t('Eva (local)');
	}

	public function getTaskTypeId(): string {
		return TextToTextHeadline::ID;
	}

	public function getExpectedRuntime(): int {
		return 60;
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
			['role' => 'system', 'content' => 'Du bist ein hilfreicher Assistent, der aussagekräftige Überschriften generiert. '
				. 'Erstelle eine kurze, prägnante Überschrift, die den Inhalt des Textes widergibt. '
				. 'Antworte in der gleichen Sprache wie der Text. '
				. 'Gib nur die Überschrift zurück, keine zusätzlichen Erklärungen.'],
			['role' => 'user', 'content' => 'Erstelle eine Überschrift für den folgenden Text:\n\n' . $prompt],
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
