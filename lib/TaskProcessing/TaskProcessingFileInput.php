<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\Files\File;
use OCP\Files\Folder;

/** Resolve TaskProcessing file values while preserving direct-ID compatibility. */
final class TaskProcessingFileInput {
	public static function resolve(Folder $userFolder, mixed $value): ?File {
		if ($value instanceof File) {
			return $value;
		}
		if (!is_numeric($value) || (int)$value <= 0) {
			return null;
		}
		$node = $userFolder->getById((int)$value)[0] ?? null;
		return $node instanceof File ? $node : null;
	}
}
