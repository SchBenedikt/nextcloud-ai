<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\OpenAICompatible;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use RuntimeException;

/** Store generated images in the user's Nextcloud files and return file IDs. */
final class EvaTextToImageProvider implements ISynchronousProvider {
	public function __construct(
		private AppConfig $appConfig,
		private OpenAICompatible $images,
		private IRootFolder $rootFolder,
		private IL10N $l,
	) {
	}

	public function getId(): string { return 'eva_ai:text2image:openai-compatible'; }
	public function getName(): string { return $this->l->t('Eva (image provider)'); }
	public function getTaskTypeId(): string { return TextToImage::ID; }
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
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$this->appConfig->setUserId($userId);
		$prompt = trim((string)($input['input'] ?? ''));
		if ($prompt === '') throw new RuntimeException('Empty image prompt');
		$count = is_numeric($input['numberOfImages'] ?? null) ? (int)$input['numberOfImages'] : 1;
		$count = max(1, min(4, $count));
		$reportProgress(0.1);
		try {
			$generated = $this->images->generateImages('Interpret the prompt in the same language as the user and create the requested image: ' . $prompt, $count, 180);
		} catch (\Throwable $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}
		$home = $this->rootFolder->getUserFolder($userId);
		$folder = $this->ensureFolder($home);
		$ids = [];
		foreach ($generated as $index => $image) {
			$extension = str_contains(strtolower($image['mime']), 'jpeg') ? 'jpg' : 'png';
			$name = 'eva-generated-' . gmdate('Ymd-His') . '-' . ($index + 1) . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
			$file = $folder->newFile($name, $image['bytes']);
			$ids[] = (int)$file->getId();
			$reportProgress(min(0.95, 0.35 + (($index + 1) / count($generated)) * 0.55));
		}
		if ($ids === []) throw new RuntimeException('The image provider returned no files.');
		$reportProgress(1.0);
		return ['images' => $ids];
	}

	private function ensureFolder(Folder $home): Folder {
		if ($home->nodeExists('EVA')) {
			$node = $home->get('EVA');
			if (!$node instanceof Folder) throw new RuntimeException('The EVA folder exists but is not a folder.');
			return $node;
		}
		return $home->newFolder('EVA');
	}
}
