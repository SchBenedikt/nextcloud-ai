<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\GeneratedImageStorage;
use OCA\EvaAi\Service\OpenAICompatible;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use RuntimeException;

/** Native Nextcloud 36 provider for editing images in the user's own Files. */
final class EvaImageToImageProvider implements ISynchronousProvider {
	private const MAX_IMAGES = 4;
	private const MAX_IMAGE_BYTES = 6_000_000;
	private const MAX_TOTAL_BYTES = 12_000_000;

	public function __construct(
		private AppConfig $appConfig,
		private OpenAICompatible $images,
		private IRootFolder $rootFolder,
		private GeneratedImageStorage $imageStorage,
		private IL10N $l,
	) {
	}

	public function getId(): string { return 'eva_ai:image2image:edit'; }
	public function getName(): string { return $this->l->t('Eva · Image editing'); }
	public function getTaskTypeId(): string { return 'core:image2image'; }
	public function getExpectedRuntime(): int { return 240; }
	public function getInputShapeEnumValues(): array { return []; }
	public function getInputShapeDefaults(): array { return []; }
	public function getOptionalInputShape(): array { return []; }
	public function getOptionalInputShapeEnumValues(): array { return []; }
	public function getOptionalInputShapeDefaults(): array { return []; }
	public function getOutputShapeEnumValues(): array { return []; }
	public function getOptionalOutputShape(): array { return []; }
	public function getOptionalOutputShapeEnumValues(): array { return []; }

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$prompt = trim((string)($input['prompt'] ?? ''));
		if ($prompt === '') throw new RuntimeException('An image-editing prompt is required.');
		$rawImages = $input['input'] ?? [];
		if (!is_array($rawImages)) $rawImages = [$rawImages];
		$home = $this->rootFolder->getUserFolder($userId);
		$nodes = [];
		foreach ($rawImages as $value) {
			$node = TaskProcessingFileInput::resolve($home, $value);
			if (!$node instanceof File) throw new RuntimeException('Every input image must be a valid Nextcloud file.');
			$nodes[(int)$node->getId()] = $node;
		}
		$nodes = array_values($nodes);
		if ($nodes === [] || count($nodes) > self::MAX_IMAGES) {
			throw new RuntimeException('Choose between one and four input images.');
		}

		$images = [];
		$totalBytes = 0;
		foreach ($nodes as $index => $node) {
			TaskProcessingProgress::report($reportProgress, min(0.25, 0.05 + ($index / count($nodes)) * 0.2));
			$mime = strtolower((string)$node->getMimeType());
			if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
				throw new RuntimeException('Input images must use PNG, JPEG, or WebP format.');
			}
			$size = (int)$node->getSize();
			if ($size <= 0 || $size > self::MAX_IMAGE_BYTES || $totalBytes + $size > self::MAX_TOTAL_BYTES) {
				throw new RuntimeException('Input images exceed the safe size limit.');
			}
			$bytes = (string)$node->getContent();
			if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES
				|| (function_exists('getimagesizefromstring') && @getimagesizefromstring($bytes) === false)) {
				throw new RuntimeException('An input image could not be read as a valid image.');
			}
			$images[] = ['bytes' => $bytes, 'mime' => $mime];
			$totalBytes += strlen($bytes);
		}

		TaskProcessingProgress::report($reportProgress, 0.3);
		try {
			$imagePrompt = 'Make the requested image edit. Any new text in the image must use the same language as the user prompt unless the user asks for another language. User instructions: ' . $prompt;
			$edited = $this->images->editImages($images, $imagePrompt, 240);
		} catch (\Throwable $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}
		TaskProcessingProgress::report($reportProgress, 0.95);
		$this->imageStorage->store($userId, $edited[0], 'edited');
		$extension = match (strtolower((string)($edited[0]['mime'] ?? ''))) {
			'image/jpeg', 'image/jpg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif' => 'gif',
			'image/avif' => 'avif',
			'image/bmp' => 'bmp',
			default => 'png',
		};
		return ['output' => TaskProcessingFileOutput::shape(EShapeType::Image, (string)$edited[0]['bytes'], $extension)];
	}
}
