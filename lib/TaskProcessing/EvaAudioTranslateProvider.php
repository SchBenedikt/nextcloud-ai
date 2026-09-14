<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCP\TaskProcessing\TaskTypes\AudioToAudioTranslate;
use RuntimeException;

final class EvaAudioTranslateProvider extends EvaAudioTranscriptionProvider {
	private const LANGUAGE_RULE = 'same language as the target language';
	protected function subtitles(): bool { return false; }
	public function getId(): string { return 'eva_ai:audio2audio:translate:openai-compatible'; }
	public function getName(): string { return $this->l->t('Eva (audio translation)'); }
	public function getTaskTypeId(): string { return AudioToAudioTranslate::ID; }
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$reportProgress(0.1);
		$file = $this->inputFile($userId, $input);
		$text = $this->audio->transcribeAudio($file['bytes'], $file['filename'], $file['mime'], false, false, 180);
		$target = trim((string)($input['target_language'] ?? '')) ?: 'the target language';
		$translated = $this->audio->chat([
			['role' => 'system', 'content' => 'Translate the transcript into ' . $target . '. Return only the translation, preserving names and meaning.'],
			['role' => 'user', 'content' => $text],
		], [], 120);
		if (isset($translated['error']) || trim((string)($translated['answer'] ?? '')) === '') throw new RuntimeException((string)($translated['error'] ?? 'Translation returned no text.'));
		$reportProgress(0.65);
		$audio = $this->audio->synthesizeSpeech(trim((string)$translated['answer']), 180);
		$id = $this->outputFile($userId, $audio['bytes'], 'mp3', 'eva-audio-translation');
		$reportProgress(1.0);
		return ['audio_output' => $id];
	}
}
