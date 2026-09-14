<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextSimplification;

final class EvaSimplificationProvider extends EvaTextTransformProvider {
	public function getId(): string { return 'eva_ai:text2text:simplification'; }
	public function getTaskTypeId(): string { return TextToTextSimplification::ID; }
	protected function instruction(): string {
		return 'Simplify the text for a broad audience: use plain language, shorter sentences and clear structure without removing important information.';
	}
}
