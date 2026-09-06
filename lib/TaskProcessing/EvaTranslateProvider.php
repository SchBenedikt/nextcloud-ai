<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use RuntimeException;

class EvaTranslateProvider implements ISynchronousProvider {

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'eva_ai:text2text:translate';
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
		$languages = [
			'de' => 'German',
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
		];
		$values = [];
		foreach ($languages as $code => $name) {
			$values[] = new ShapeEnumValue($name, $code);
		}

		return [
			'origin_language' => array_merge([
				new ShapeEnumValue($this->l->t('Detect language'), 'detect_language'),
			], $values),
			'target_language' => $values,
		];
	}

	public function getInputShapeDefaults(): array {
		return [
			'origin_language' => 'detect_language',
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

		$originLanguage = (string)($input['origin_language'] ?? 'detect_language');
		$targetLanguage = (string)($input['target_language'] ?? 'en');

		$reportProgress(0.1);

		$languageMap = [
			'de' => 'German',
			'en' => 'English',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'pt' => 'Portuguese',
			'ru' => 'Russian',
			'zh' => 'Chinese',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'ar' => 'Arabic',
			'pl' => 'Polish',
			'tr' => 'Turkish',
		];

		$targetLangName = $languageMap[$targetLanguage] ?? $targetLanguage;

		$systemPrompt = 'You are a professional translator. '
			. 'Translate the text into ' . $targetLangName . '. '
			. 'Return only the translation, without additional explanations or comments. '
			. 'Preserve the original tone and style.';

		if ($originLanguage !== 'auto' && $originLanguage !== 'detect_language') {
			$originLangName = $languageMap[$originLanguage] ?? $originLanguage;
			$systemPrompt = 'You are a professional translator. '
				. 'Translate the text from ' . $originLangName . ' into ' . $targetLangName . '. '
				. 'Return only the translation, without additional explanations or comments. '
				. 'Preserve the original tone and style.';
		}

		$messages = [
			['role' => 'system', 'content' => $systemPrompt],
			['role' => 'user', 'content' => $prompt],
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
