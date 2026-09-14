<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextFormalization;

final class EvaFormalizationProvider extends EvaTextTransformProvider {
	public function getId(): string { return 'eva_ai:text2text:formalization'; }
	public function getTaskTypeId(): string { return TextToTextFormalization::ID; }
	protected function instruction(): string {
		return 'Rewrite the text in a clear, formal and professional register while preserving the original meaning and concrete details.';
	}
}
