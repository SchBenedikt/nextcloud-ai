<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\GenerateEmoji;

final class EvaEmojiProvider extends EvaTextTransformProvider {
	public function getId(): string { return 'eva_ai:generateemoji'; }
	public function getTaskTypeId(): string { return GenerateEmoji::ID; }
	protected function instruction(): string {
		return 'Choose one or two Unicode emoji that best represent the input. Return only the emoji characters, with no words, punctuation or explanation.';
	}
}
