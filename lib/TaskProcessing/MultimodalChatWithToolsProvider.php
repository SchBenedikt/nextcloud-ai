<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCP\TaskProcessing\TaskTypes\MultimodalChatWithTools;

/** Multimodal alias using the same central TaskProcessing tool policy. */
class MultimodalChatWithToolsProvider extends TextToTextChatWithToolsProvider {
	private const LANGUAGE_RULE = 'same language as the user';
	public function getId(): string { return 'eva_ai:multimodal-chatwithtools'; }
	public function getName(): string { return $this->l->t('Eva · Multimodal Tools'); }
	public function getTaskTypeId(): string { return MultimodalChatWithTools::ID; }

	public function process(?string $userId, array $input, callable $reportProgress): array {
		return $this->processWithIntermediateOutput($userId, $input, $reportProgress);
	}

	public function processWithIntermediateOutput(
		?string $userId,
		array $input,
		callable $reportProgress,
		?callable $reportIntermediateOutput = null,
		bool $preferStreaming = false,
	): array {
		$result = parent::processWithIntermediateOutput($userId, $input, $reportProgress, $reportIntermediateOutput, $preferStreaming);
		$result['output_attachments'] = [];
		return $result;
	}
}
