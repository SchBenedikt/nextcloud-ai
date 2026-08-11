<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextChangeTone;
use RuntimeException;

class EvaChangeToneProvider implements ISynchronousProvider {

	public function __construct(
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva-ai:text2text:change-tone';
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
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [
			'tone' => new ShapeDescriptor(
				$this->l->t('Tone'),
				$this->l->t('The desired tone for the text.'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'tone' => [
				'formal' => 'Formell',
				'informal' => 'Informell',
				'friendly' => 'Freundlich',
				'professional' => 'Professionell',
				'humorous' => 'Humorvoll',
				'persuasive' => 'Überzeugend',
				'concise' => 'Knapp',
				'detailed' => 'Detailliert',
			],
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [
			'tone' => 'formal',
		];
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

		$prompt = trim((string)($input['input'] ?? ''));
		if ($prompt === '') {
			throw new RuntimeException('Leere Eingabe');
		}

		$tone = (string)($input['tone'] ?? 'formal');

		$reportProgress(0.1);

		$toneDescriptions = [
			'formal' => 'formell und höflich',
			'informal' => 'informell und locker',
			'friendly' => 'freundlich und zugänglich',
			'professional' => 'professionell und sachlich',
			'humorous' => 'humorvoll und locker',
			'persuasive' => 'überzeugend undargumentativ',
			'concise' => 'kurz und bündig',
			'detailed' => 'detailliert und ausführlich',
		];

		$toneDescription = $toneDescriptions[$tone] ?? $tone;

		$messages = [
			['role' => 'system', 'content' => 'Du bist ein hilfreicher Assistent, der den Tonfall von Texten ändert. '
				. 'Schreibe den Text im Tonfall: ' . $toneDescription . '. '
				. 'Behalte den Inhalt bei, ändere nur den Stil und Tonfall. '
				. 'Antworte in der gleichen Sprache wie der Originaltext. '
				. 'Gib nur den umgeschriebenen Text zurück, keine zusätzlichen Erklärungen.'],
			['role' => 'user', 'content' => 'Ändere den Tonfall des folgenden Textes:\n\n' . $prompt],
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
