<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCP\TaskProcessing\TaskTypes\TextToTextImprove;

final class EvaImproveProvider extends EvaTextTransformProvider {
	public function getId(): string { return 'eva_ai:text2text:improve'; }
	public function getTaskTypeId(): string { return TextToTextImprove::ID; }
	protected function instruction(): string {
		return 'Improve the clarity, grammar, spelling and readability of the text while preserving its meaning, facts and intent.';
	}
}
