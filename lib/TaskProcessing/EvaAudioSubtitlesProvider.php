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
		$this->reportProgress($reportProgress, 0.1);
		$file = $this->inputFile($userId, $input);
		$this->reportProgress($reportProgress, 0.25);
		$vtt = $this->audio->transcribeAudio($file['bytes'], $file['filename'], $file['mime'], false, true, 180);
		if (!str_starts_with($vtt, 'WEBVTT')) $vtt = "WEBVTT\n\n" . $vtt;
		$this->reportProgress($reportProgress, 0.95);
		$output = $this->outputFile($userId, $vtt . "\n", 'vtt', 'eva-subtitles', \OCP\TaskProcessing\EShapeType::File);
		return ['output' => $output];
	}
}
