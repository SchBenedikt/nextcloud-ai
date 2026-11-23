<?php
declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\SynchronousProviderOptions;

/** Nextcloud 35+ adapter for streaming transcript and translation text. */
final class StreamingEvaAudioTranslateProvider extends EvaAudioTranslateProvider implements ISynchronousOptionsAwareProvider {
	public function process(
		?string $userId,
		array $input,
		callable $reportProgress,
		SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		return $this->processTranslation(
			$userId,
			$input,
			$reportProgress,
			$options->getReportIntermediateOutput(),
			$options->getPreferStreaming(),
			$options->getIncludeWatermarks(),
		);
	}
}
