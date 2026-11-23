<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCP\TaskProcessing\TaskTypes\AudioToAudioTranslate;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ShapeDescriptor;
use RuntimeException;

class EvaAudioTranslateProvider extends EvaAudioTranscriptionProvider {
	private const LANGUAGES = [
		'de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'es' => 'Español',
		'it' => 'Italiano', 'nl' => 'Nederlands', 'pt' => 'Português', 'ru' => 'Русский',
		'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어', 'ar' => 'العربية',
		'pl' => 'Polski', 'tr' => 'Türkçe',
	];
	private const LANGUAGE_NAMES = [
		'de' => 'German', 'en' => 'English', 'fr' => 'French', 'es' => 'Spanish',
		'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese', 'ru' => 'Russian',
		'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'ar' => 'Arabic',
		'pl' => 'Polish', 'tr' => 'Turkish',
	];

	protected function subtitles(): bool { return false; }
	public function getId(): string { return 'eva_ai:audio2audio:translate:openai-compatible'; }
	public function getName(): string { return $this->l->t('Eva (audio translation)'); }
	public function getTaskTypeId(): string { return AudioToAudioTranslate::ID; }
	public function getInputShapeEnumValues(): array {
		$languages = [];
		foreach (self::LANGUAGES as $code => $name) {
			$languages[] = new ShapeEnumValue($name, $code);
		}

		return [
			'origin_language' => array_merge([
				new ShapeEnumValue($this->l->t('Detect language'), 'detect_language'),
			], $languages),
			'target_language' => $languages,
		];
	}
	public function getInputShapeDefaults(): array {
		return ['origin_language' => 'detect_language', 'target_language' => 'en'];
	}
	public function process(?string $userId, array $input, callable $reportProgress): array {
		return $this->processTranslation($userId, $input, $reportProgress);
	}

	public function getOptionalOutputShape(): array {
		return [
			'text_input' => new ShapeDescriptor(
				$this->l->t('Audio transcription'),
				$this->l->t('The transcribed audio input'),
				EShapeType::Text,
			),
			'text_output' => new ShapeDescriptor(
				$this->l->t('Text output'),
				$this->l->t('The text translation'),
				EShapeType::Text,
			),
		];
	}

	/** @return array<string, mixed> */
	protected function processTranslation(
		?string $userId,
		array $input,
		callable $reportProgress,
		?callable $reportIntermediateOutput = null,
		bool $preferStreaming = false,
		bool $includeWatermarks = false,
	): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$originCode = trim((string)($input['origin_language'] ?? 'detect_language'));
		$targetCode = trim((string)($input['target_language'] ?? 'en'));
		if ($originCode !== 'detect_language' && !isset(self::LANGUAGE_NAMES[$originCode])) {
			throw new RuntimeException('The selected source language is not supported.');
		}
		if (!isset(self::LANGUAGE_NAMES[$targetCode])) {
			throw new RuntimeException('The selected target language is not supported.');
		}
		$target = self::LANGUAGE_NAMES[$targetCode];
		$sourceInstruction = $originCode === 'detect_language'
			? 'Translate the transcript'
			: 'Translate the transcript from ' . self::LANGUAGE_NAMES[$originCode];
		$this->appConfig->setUserId($userId);
		$this->reportProgress($reportProgress, 0.1);
		$file = $this->inputFile($userId, $input);
		$text = $this->audio->transcribeAudio(
			$file['bytes'],
			$file['filename'],
			$file['mime'],
			false,
			false,
			180,
			$originCode === 'detect_language' ? null : $originCode,
		);
		$this->reportProgress($reportProgress, 0.35);
		$translated = $this->audio->chat([
			['role' => 'system', 'content' => $sourceInstruction . ' into ' . $target . '. Use the same language as the selected target language. Return only the translation, preserving names and meaning.'],
			['role' => 'user', 'content' => $text],
		], [], 120);
		if (isset($translated['error']) || trim((string)($translated['answer'] ?? '')) === '') throw new RuntimeException((string)($translated['error'] ?? 'Translation returned no text.'));
		$this->reportProgress($reportProgress, 0.65);
		$translatedText = trim((string)$translated['answer']);
		$watermark = $includeWatermarks ? "\n\n" . $this->l->t('This was generated using Artificial Intelligence.') : '';
		if ($preferStreaming && $reportIntermediateOutput !== null) {
			if (!$reportIntermediateOutput(['text_input' => $text . $watermark])) {
				throw new TaskCancelledException('Audio translation task cancelled.');
			}
			if (!$reportIntermediateOutput(['text_input' => $text . $watermark, 'text_output' => $translatedText . $watermark])) {
				throw new TaskCancelledException('Audio translation task cancelled.');
			}
		}
		$audio = $this->audio->synthesizeSpeech($translatedText . $watermark, 180);
		$this->reportProgress($reportProgress, 0.95);
		$output = $this->outputFile($userId, $audio['bytes'], 'mp3', 'eva-audio-translation');
		return ['audio_output' => $output, 'text_input' => $text . $watermark, 'text_output' => $translatedText . $watermark];
	}
}
