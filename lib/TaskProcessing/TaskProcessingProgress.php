<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

/** Shared cancellation handling for synchronous TaskProcessing providers. */
final class TaskProcessingProgress {
	/**
	 * Report progress and stop immediately when Nextcloud cancels the task.
	 *
	 * @param callable(float):bool $reportProgress
	 */
	public static function report(callable $reportProgress, float $progress): void {
		if ($reportProgress($progress) === false) {
			throw new TaskCancelledException('Task cancelled');
		}
	}
}
