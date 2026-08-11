<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use RuntimeException;

class EvaTranslateProvider implements ISynchronousProvider {

	public function __construct(
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva-ai:text2text:translate';
	}

	public function getName(): string {
		return $this->l->t('Eva (local)');
	}

	public function getTaskTypeId(): string {
		return TextToTextTranslate::ID;
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
			'origin_language' => new ShapeDescriptor(
				$this->l->t('Source language'),
				$this->l->t('The language of the text to translate.'),
				EShapeType::Enum
			),
			'target_language' => new ShapeDescriptor(
				$this->l->t('Target language'),
				$this->l->t('The language to translate to.'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'origin_language' => [
				'de' => 'Deutsch',
				'en' => 'English',
				'fr' => 'Français',
				'es' => 'Español',
				'it' => 'Italiano',
				'nl' => 'Nederlands',
				'pt' => 'Português',
				'ru' => 'Русский',
				'zh' => '中文',
				'ja' => '日本語',
				'ko' => '한국어',
				'ar' => 'العربية',
				'pl' => 'Polski',
				'tr' => 'Türkçe',
			],
			'target_language' => [
				'de' => 'Deutsch',
				'en' => 'English',
				'fr' => 'Français',
				'es' => 'Español',
				'it' => 'Italiano',
				'nl' => 'Nederlands',
				'pt' => 'Português',
				'ru' => 'Русский',
				'zh' => '中文',
				'ja' => '日本語',
				'ko' => '한국어',
				'ar' => 'العربية',
				'pl' => 'Polski',
				'tr' => 'Türkçe',
			],
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [
			'origin_language' => 'auto',
			'target_language' => 'en',
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

		$originLanguage = (string)($input['origin_language'] ?? 'auto');
		$targetLanguage = (string)($input['target_language'] ?? 'en');

		$reportProgress(0.1);

		$languageMap = [
			'de' => 'Deutsch',
			'en' => 'Englisch',
			'fr' => 'Französisch',
			'es' => 'Spanisch',
			'it' => 'Italienisch',
			'nl' => 'Niederländisch',
			'pt' => 'Portugiesisch',
			'ru' => 'Russisch',
			'zh' => 'Chinesisch',
			'ja' => 'Japanisch',
			'ko' => 'Koreanisch',
			'ar' => 'Arabisch',
			'pl' => 'Polnisch',
			'tr' => 'Türkisch',
		];

		$targetLangName = $languageMap[$targetLanguage] ?? $targetLanguage;

		$systemPrompt = 'Du bist ein professioneller Übersetzer. '
			. 'Übersetze den Text ins ' . $targetLangName . '. '
			. 'Gib nur die Übersetzung zurück, keine zusätzlichen Erklärungen oder Kommentare. '
			. 'Behalte den ursprünglichen Ton und Stil bei.';

		if ($originLanguage !== 'auto') {
			$originLangName = $languageMap[$originLanguage] ?? $originLanguage;
			$systemPrompt = 'Du bist ein professioneller Übersetzer. '
				. 'Übersetze den Text von ' . $originLangName . ' ins ' . $targetLangName . '. '
				. 'Gib nur die Übersetzung zurück, keine zusätzlichen Erklärungen oder Kommentare. '
				. 'Behalte den ursprünglichen Ton und Stil bei.';
		}

		$messages = [
			['role' => 'system', 'content' => $systemPrompt],
			['role' => 'user', 'content' => $prompt],
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
