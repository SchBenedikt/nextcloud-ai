<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCP\TaskProcessing\TaskTypes\AudioToTextSubtitles;
use RuntimeException;

final class EvaAudioSubtitlesProvider extends EvaAudioTranscriptionProvider {
	private const LANGUAGE_RULE = 'same language as the audio';
	public function getId(): string { return 'eva_ai:audio2text:subtitles:openai-compatible'; }
	public function getName(): string { return $this->l->t('Eva (subtitles)'); }
	public function getTaskTypeId(): string { return AudioToTextSubtitles::ID; }
	protected function subtitles(): bool { return true; }
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$reportProgress(0.1);
		$file = $this->inputFile($userId, $input);
		$reportProgress(0.25);
		$vtt = $this->audio->transcribeAudio($file['bytes'], $file['filename'], $file['mime'], false, true, 180);
		if (!str_starts_with($vtt, 'WEBVTT')) $vtt = "WEBVTT\n\n" . $vtt;
		$reportProgress(0.8);
		$id = $this->outputFile($userId, $vtt . "\n", 'vtt');
		$reportProgress(1.0);
		return ['output' => $id];
	}
}
