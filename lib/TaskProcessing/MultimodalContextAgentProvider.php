<?php
declare(strict_types=1);
namespace OCA\EvaAi\TaskProcessing;
use OCP\TaskProcessing\TaskTypes\MultimodalContextAgentInteraction;

/** Confirmation-aware ContextAgent variant with bounded image attachments. */
final class MultimodalContextAgentProvider extends AgentInteractionProvider {
	private const LANGUAGE_RULE = 'same language as the user';
	public function getId(): string { return 'eva_ai:contextagent:multimodal-interaction'; }
	public function getName(): string { return $this->l->t('Eva · Multimodal Agent'); }
	public function getTaskTypeId(): string { return MultimodalContextAgentInteraction::ID; }

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$result = parent::process($userId, $input, $reportProgress);
		$result['output_attachments'] = [];
		$result['sources'] = [];
		return $result;
	}
}
