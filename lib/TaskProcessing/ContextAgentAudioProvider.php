<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCA\EvaAi\TaskProcessing\AgentInteractionProvider;
use OCP\TaskProcessing\TaskTypes\ContextAgentAudioInteraction;
use RuntimeException;

/** Voice adapter around EVA's existing confirmation-aware ContextAgent. */
final class ContextAgentAudioProvider extends EvaAudioTranscriptionProvider {
	private const LANGUAGE_RULE = 'same language as the voice message';
	public function __construct(
		\OCA\EvaAi\Service\AppConfig $appConfig,
		\OCA\EvaAi\Service\OpenAICompatible $audio,
		\OCP\Files\IRootFolder $rootFolder,
		\OCP\IL10N $l,
		private AgentInteractionProvider $agent,
	) { parent::__construct($appConfig, $audio, $rootFolder, $l); }
	protected function subtitles(): bool { return false; }
	public function getId(): string { return 'eva_ai:contextagent:audio-interaction'; }
	public function getName(): string { return $this->l->t('Eva · Agent audio'); }
	public function getTaskTypeId(): string { return ContextAgentAudioInteraction::ID; }
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$reportProgress(0.1);
		$file = $this->inputFile($userId, $input);
		$text = $this->audio->transcribeAudio($file['bytes'], $file['filename'], $file['mime'], false, false, 180);
		$agentInput = $input;
		$agentInput['input'] = $text;
		$agentResult = $this->agent->process($userId, $agentInput, static function (float $progress): void {});
		$answer = trim((string)($agentResult['output'] ?? ''));
		if ($answer === '') throw new RuntimeException('ContextAgent returned no answer.');
		$reportProgress(0.65);
		$audio = $this->audio->synthesizeSpeech($answer, 180);
		$id = $this->outputFile($userId, $audio['bytes'], 'mp3', 'eva-agent-audio');
		$reportProgress(1.0);
		return [
			'input_transcript' => $text,
			'output' => $id,
			'output_transcript' => $answer,
			'conversation_token' => (string)($agentResult['conversation_token'] ?? ''),
			'actions' => (string)($agentResult['actions'] ?? ''),
		];
	}
}
