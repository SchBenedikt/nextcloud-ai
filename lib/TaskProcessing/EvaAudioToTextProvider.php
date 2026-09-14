<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use RuntimeException;

final class EvaAudioToTextProvider extends EvaAudioTranscriptionProvider {
	private const LANGUAGE_RULE = 'same language as the audio';
	public function getId(): string { return 'eva_ai:audio2text:openai-compatible'; }
	public function getName(): string { return $this->l->t('Eva (audio transcription)'); }
	public function getTaskTypeId(): string { return AudioToText::ID; }
	protected function subtitles(): bool { return false; }
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$reportProgress(0.1);
		$file = $this->inputFile($userId, $input);
		$reportProgress(0.25);
		$text = $this->audio->transcribeAudio($file['bytes'], $file['filename'], $file['mime'], false, false, 180);
		$reportProgress(1.0);
		return ['output' => $text];
	}
}
