<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use RuntimeException;

/**
 * Shared implementation for local text transformation task providers.
 *
 * Keeping the execution path in one class makes the additional Core task
 * types behave exactly like EVA's existing summary/reformulation providers:
 * bounded progress, the user's configured local model, language preservation,
 * and no accidental tool execution.
 */
abstract class EvaTextTransformProvider implements ISynchronousProvider {
	public function __construct(
		protected AppConfig $appConfig,
		protected Ollama $ollama,
		protected IL10N $l,
	) {
	}

	abstract protected function instruction(): string;

	public function getName(): string {
		return $this->l->t('Eva (local)');
	}

	public function getExpectedRuntime(): int {
		return 120;
	}

	public function getInputShapeEnumValues(): array { return []; }
	public function getInputShapeDefaults(): array { return []; }
	public function getOptionalInputShape(): array { return []; }
	public function getOptionalInputShapeEnumValues(): array { return []; }
	public function getOptionalInputShapeDefaults(): array { return []; }
	public function getOutputShapeEnumValues(): array { return []; }
	public function getOptionalOutputShape(): array { return []; }
	public function getOptionalOutputShapeEnumValues(): array { return []; }

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null) {
			throw new RuntimeException('No user context');
		}
		$this->appConfig->setUserId($userId);
		$text = trim((string)($input['input'] ?? ''));
		if ($text === '') {
			throw new RuntimeException('Empty input');
		}

		$reportProgress(0.1);
		$result = $this->ollama->chat([
			['role' => 'system', 'content' => $this->instruction() . ' Answer in the same language as the input and return only the transformed text, without commentary.'],
			['role' => 'user', 'content' => $text],
		], [], null, static function (float $progress) use ($reportProgress): void {
			$reportProgress(0.3 + min(0.6, max(0.0, $progress)) * 0.6);
		}, $this->appConfig->get('summary_model'));

		if (isset($result['error'])) {
			throw new RuntimeException((string)$result['error']);
		}
		$answer = trim((string)($result['answer'] ?? ''));
		if ($answer === '') {
			throw new RuntimeException('Leere Antwort vom Modell');
		}
		$reportProgress(0.9);
		return ['output' => $answer];
	}
}
