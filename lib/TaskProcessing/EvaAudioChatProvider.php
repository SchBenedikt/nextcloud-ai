<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCP\TaskProcessing\TaskTypes\AudioToAudioChat;
use RuntimeException;

final class EvaAudioChatProvider extends EvaAudioTranscriptionProvider {
	private const LANGUAGE_RULE = 'same language as the voice message';
	protected function subtitles(): bool { return false; }
	public function getId(): string { return 'eva_ai:audio2audio:chat:openai-compatible'; }
	public function getName(): string { return $this->l->t('Eva (audio chat)'); }
	public function getTaskTypeId(): string { return AudioToAudioChat::ID; }
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$reportProgress(0.1);
		$file = $this->inputFile($userId, $input);
		$text = $this->audio->transcribeAudio($file['bytes'], $file['filename'], $file['mime'], false, false, 180);
		$messages = [['role' => 'system', 'content' => trim((string)($input['system_prompt'] ?? '')) ?: 'You are a helpful assistant. Answer in the same language as the voice message.']];
		foreach (is_array($input['history'] ?? null) ? array_slice($input['history'], -12) : [] as $history) {
			if (is_string($history) && trim($history) !== '') $messages[] = ['role' => 'user', 'content' => mb_substr($history, 0, 4000)];
		}
		$messages[] = ['role' => 'user', 'content' => $text];
		$answer = $this->audio->chat($messages, [], 120);
		if (isset($answer['error']) || trim((string)($answer['answer'] ?? '')) === '') throw new RuntimeException((string)($answer['error'] ?? 'Audio chat returned no answer.'));
		$reportProgress(0.65);
		$audio = $this->audio->synthesizeSpeech(trim((string)$answer['answer']), 180);
		$id = $this->outputFile($userId, $audio['bytes'], 'mp3', 'eva-audio-chat');
		$reportProgress(1.0);
		return ['input_transcript' => $text, 'output' => $id, 'output_transcript' => trim((string)$answer['answer'])];
	}
}
