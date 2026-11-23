<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\GeneratedImageStorage;
use OCA\EvaAi\Service\OpenAICompatible;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
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
		$rawIds = $input['input'] ?? [];
		if (!is_array($rawIds)) $rawIds = [$rawIds];
		$ids = [];
		foreach ($rawIds as $rawId) {
			$id = is_numeric($rawId) ? (int)$rawId : 0;
			if ($id <= 0) throw new RuntimeException('Every input image must be a valid Nextcloud file.');
			if (!in_array($id, $ids, true)) $ids[] = $id;
		}
		if ($ids === [] || count($ids) > self::MAX_IMAGES) {
			throw new RuntimeException('Choose between one and four input images.');
		}

		$home = $this->rootFolder->getUserFolder($userId);
		$images = [];
		$totalBytes = 0;
		foreach ($ids as $index => $id) {
			$reportProgress(min(0.25, 0.05 + ($index / count($ids)) * 0.2));
			$node = $home->getById($id)[0] ?? null;
			if (!$node instanceof File) throw new RuntimeException('An input image is not available in your Nextcloud Files.');
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

		$reportProgress(0.3);
		try {
			$edited = $this->images->editImages($images, $prompt, 240);
		} catch (\Throwable $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}
		$reportProgress(0.9);
		$fileId = $this->imageStorage->store($userId, $edited[0], 'edited');
		$reportProgress(1.0);
		return ['output' => $fileId];
	}
}
