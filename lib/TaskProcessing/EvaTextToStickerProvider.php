<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\OpenAICompatible;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use RuntimeException;

/** Supplies the optional sticker task type registered by Nextcloud Assistant. */
final class EvaTextToStickerProvider implements ISynchronousProvider {
	public function __construct(
		private AppConfig $appConfig,
		private OpenAICompatible $images,
		private IL10N $l,
	) {
	}

	public function getId(): string { return 'eva_ai:assistant:text2sticker'; }
	public function getName(): string { return $this->l->t('Eva (sticker generator)'); }
	public function getTaskTypeId(): string { return 'assistant:text2sticker'; }
	public function getExpectedRuntime(): int { return 180; }
	public function getInputShapeEnumValues(): array { return []; }
	public function getInputShapeDefaults(): array { return []; }
	public function getOptionalInputShape(): array { return []; }
	public function getOptionalInputShapeEnumValues(): array { return []; }
	public function getOptionalInputShapeDefaults(): array { return []; }
	public function getOutputShapeEnumValues(): array { return []; }
	public function getOptionalOutputShape(): array { return []; }
	public function getOptionalOutputShapeEnumValues(): array { return []; }

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') {
			throw new RuntimeException('No user context');
		}
		$this->appConfig->setUserId($userId);
		$prompt = trim((string)($input['input'] ?? ''));
		if ($prompt === '' || mb_strlen($prompt) > 8000) {
			throw new RuntimeException('Enter a sticker prompt of 1 to 8000 characters.');
		}

		$reportProgress(0.1);
		try {
			$images = $this->images->generateImages(
				'Create one expressive sticker illustration on a neutral background based on this description. Any lettering in the image must use the same language as the user prompt: ' . $prompt,
				1,
				180,
			);
		} catch (\Throwable $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}
		$image = $images[0]['bytes'] ?? null;
		if (!is_string($image) || $image === '') {
			throw new RuntimeException('The image provider returned no usable sticker.');
		}
		$reportProgress(1.0);
		return ['image' => $image];
	}
}
