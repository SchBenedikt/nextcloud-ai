<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\TaskProcessing\EShapeType;

/** Return file data in the format accepted by this Nextcloud core version. */
final class TaskProcessingFileOutput {
	public static function shape(EShapeType $shapeType, string $data, string $extension = ''): mixed {
		$class = '\\OCP\\TaskProcessing\\FileShaped';
		if (class_exists($class)) {
			return new $class($shapeType, $data, $extension);
		}
		// FileShaped was added in Nextcloud 35; older supported cores accept
		// the file bytes directly and determine the output from its task shape.
		return $data;
	}
}
