<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\SynchronousProviderOptions;

/** Nextcloud 35+ adapter for the Assistant's streaming chat-with-tools task. */
final class StreamingTextToTextChatWithToolsProvider extends TextToTextChatWithToolsProvider implements ISynchronousOptionsAwareProvider {
	public function process(
		?string $userId,
		array $input,
		callable $reportProgress,
		SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		return $this->processWithIntermediateOutput($userId, $input, $reportProgress, $options->getReportIntermediateOutput(), $options->getPreferStreaming());
	}
}
