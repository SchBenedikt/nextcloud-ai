<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCA\EvaAi\TaskProcessing\AgentInteractionProvider;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ShapeDescriptor;
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
	public function getOptionalOutputShape(): array {
		return [
			'sources' => new ShapeDescriptor(
				$this->l->t('Sources'),
				$this->l->t('Names of the EVA knowledge sources used to answer the chat message.'),
				EShapeType::ListOfTexts,
			),
		];
	}
	public function getOptionalInputShape(): array {
		return [
			'memories' => new ShapeDescriptor(
				$this->l->t('Memories'),
				$this->l->t('The memories to be injected into the chat session.'),
				EShapeType::ListOfTexts,
			),
			'user_instructions' => new ShapeDescriptor(
				$this->l->t('User instructions'),
				$this->l->t('Additional instructions from the Nextcloud Assistant chat settings.'),
				EShapeType::Text,
			),
		];
	}
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$this->reportProgress($reportProgress, 0.1);
		$file = $this->inputFile($userId, $input);
		$text = $this->audio->transcribeAudio($file['bytes'], $file['filename'], $file['mime'], false, false, 180);
		$this->reportProgress($reportProgress, 0.2);
		$agentInput = $input;
		$agentInput['input'] = $text;
		$agentResult = $this->agent->process($userId, $agentInput, function (float $progress) use ($reportProgress): bool {
			return $reportProgress(0.2 + min(1.0, max(0.0, $progress)) * 0.55) !== false;
		});
		$answer = trim((string)($agentResult['output'] ?? ''));
		if ($answer === '') throw new RuntimeException('ContextAgent returned no answer.');
		$this->reportProgress($reportProgress, 0.8);
		$audio = $this->audio->synthesizeSpeech($answer, 180);
		$this->reportProgress($reportProgress, 0.95);
		$id = $this->outputFile($userId, $audio['bytes'], 'mp3', 'eva-agent-audio');
		return [
			'input_transcript' => $text,
			'output' => $id,
			'output_transcript' => $answer,
			'conversation_token' => (string)($agentResult['conversation_token'] ?? ''),
			'actions' => (string)($agentResult['actions'] ?? ''),
			'sources' => is_array($agentResult['sources'] ?? null) ? $agentResult['sources'] : [],
		];
	}
}
