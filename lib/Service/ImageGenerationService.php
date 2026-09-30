<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use RuntimeException;

/** Creates and lists user-owned generated images inside Nextcloud Files. */
final class ImageGenerationService {
	public function __construct(
		private AppConfig $config,
		private OpenAICompatible $provider,
		private IRootFolder $rootFolder,
		private IURLGenerator $urls,
	) {
	}

	/** @return list<array{id:int,name:string,mime:string,size:int,mtime:int,previewUrl:string,downloadUrl:string}> */
	public function listForUser(string $userId, int $limit = 24): array {
		$home = $this->rootFolder->getUserFolder($userId);
		if (!$home->nodeExists('EVA')) return [];
		$folder = $home->get('EVA');
		if (!$folder instanceof Folder) return [];
		$images = [];
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (!$node instanceof File
				|| !preg_match('/^eva-generated-[a-zA-Z0-9-]+\.(?:png|jpe?g|webp)$/i', $name)) {
				continue;
			}
			$images[] = [
				'id' => (int)$node->getId(),
				'name' => $name,
				'mime' => $node->getMimetype(),
				'size' => (int)$node->getSize(),
				'mtime' => (int)$node->getMTime(),
				'previewUrl' => $this->urls->linkToRoute('core.Preview.getPreviewByFileId', [
					'fileId' => $node->getId(), 'x' => 768, 'y' => 768, 'a' => 1,
				]),
				'downloadUrl' => $this->urls->linkToOCSRouteAbsolute('eva_ai.image.download', ['id' => $node->getId()]),
			];
		}
		usort($images, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
		return array_slice($images, 0, max(1, min(50, $limit)));
	}

	public function findGeneratedFile(string $userId, int $fileId): ?File {
		$home = $this->rootFolder->getUserFolder($userId);
		if (!$home->nodeExists('EVA')) return null;
		$folder = $home->get('EVA');
		if (!$folder instanceof Folder) return null;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && (int)$node->getId() === $fileId
				&& preg_match('/^eva-generated-[a-zA-Z0-9-]+\.(?:png|jpe?g|webp)$/i', $node->getName())) {
				return $node;
			}
		}
		return null;
	}

	/** @return list<array{id:int,name:string,mime:string,size:int,mtime:int,previewUrl:string,downloadUrl:string}> */
	public function generate(string $userId, string $prompt, int $count): array {
		$this->config->setUserId($userId);
		$provider = strtolower(trim((string)$this->config->get('chat_provider')));
		if ($provider === '' || in_array($provider, ['ollama', 'groq'], true)) {
			throw new RuntimeException('Select an OpenAI-compatible provider with an image model in EVA Settings first.');
		}
		$generated = $this->provider->generateImages(
			'Interpret the prompt in the same language as the user and create the requested image: ' . $prompt,
			$count,
			180,
		);
		$home = $this->rootFolder->getUserFolder($userId);
		$folder = $this->ensureFolder($home);
		$createdNames = [];
		foreach ($generated as $image) {
			$info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($image['bytes']) : false;
			$mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
			$extension = match ($mime) {
				'image/png' => 'png',
				'image/jpeg' => 'jpg',
				'image/webp' => 'webp',
				default => throw new RuntimeException('The image provider returned an unsupported image format.'),
			};
			$name = 'eva-generated-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
			$folder->newFile($name, $image['bytes']);
			$createdNames[] = $name;
		}
		return array_values(array_filter(
			$this->listForUser($userId, 50),
			static fn(array $image): bool => in_array($image['name'], $createdNames, true),
		));
	}

	private function ensureFolder(Folder $home): Folder {
		if ($home->nodeExists('EVA')) {
			$folder = $home->get('EVA');
			if (!$folder instanceof Folder) throw new RuntimeException('The EVA folder exists but is not a folder.');
			return $folder;
		}
		return $home->newFolder('EVA');
	}
}
