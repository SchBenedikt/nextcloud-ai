<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use RuntimeException;

/** Raised when Nextcloud asks a synchronous provider to stop its work. */
final class TaskCancelledException extends RuntimeException {
}
