<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use RuntimeException;

/** Stores generated and edited images in the user's private EVA folder. */
final class GeneratedImageStorage {
	private const MAX_IMAGE_BYTES = 15_000_000;

	public function __construct(private IRootFolder $rootFolder) {
	}

	/**
	 * @param array{bytes:string,mime:string} $image
	 */
	public function store(string $userId, array $image, string $kind = 'generated'): int {
		$bytes = $image['bytes'];
		if ($userId === '' || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
			throw new RuntimeException('The image is empty or exceeds the safe file size limit.');
		}

		$mime = strtolower(trim(explode(';', $image['mime'], 2)[0]));
		if (function_exists('getimagesizefromstring')) {
			$details = @getimagesizefromstring($bytes);
			if ($details === false || !is_string($details['mime'] ?? null)) {
				throw new RuntimeException('The image provider returned invalid image data.');
			}
			$mime = $details['mime'];
		}
		$extension = match (strtolower($mime)) {
			'image/jpeg', 'image/jpg' => 'jpg',
			'image/webp' => 'webp',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/avif' => 'avif',
			'image/bmp' => 'bmp',
			default => throw new RuntimeException('The image provider returned an unsupported image format.'),
		};
		$kind = preg_replace('/[^a-z0-9_-]/i', '', $kind) ?: 'generated';
		$folder = $this->ensureFolder($this->rootFolder->getUserFolder($userId));
		$name = 'eva-' . $kind . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
		$file = $folder->newFile($name, $bytes);
		return (int)$file->getId();
	}

	private function ensureFolder(Folder $home): Folder {
		if ($home->nodeExists('EVA')) {
			$node = $home->get('EVA');
			if (!$node instanceof Folder) {
				throw new RuntimeException('The EVA folder exists but is not a folder.');
			}
			return $node;
		}
		return $home->newFolder('EVA');
	}
}
