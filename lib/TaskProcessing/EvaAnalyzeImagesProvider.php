<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Ollama;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use RuntimeException;

/** Vision provider for the core AnalyzeImages task type.
 *
 * The message shape is intentionally provider-neutral. Ollama receives its
 * native `images` array while OpenAI-compatible adapters translate it to
 * data-URI image parts. This keeps the TaskProcessing provider independent of
 * the selected chat backend and avoids downloading files to a temporary path.
 */
final class EvaAnalyzeImagesProvider implements ISynchronousProvider {
	private const MAX_IMAGES = 4;
	private const MAX_IMAGE_BYTES = 6_000_000;
	private const MAX_TOTAL_BYTES = 12_000_000;

	public function __construct(
		private AppConfig $appConfig,
		private Ollama $ollama,
		private IRootFolder $rootFolder,
		private IL10N $l,
	) {
	}

	public function getId(): string { return 'eva_ai:image2text:analyze'; }
	public function getName(): string { return $this->l->t('Eva (vision)'); }
	public function getTaskTypeId(): string { return AnalyzeImages::ID; }
	public function getExpectedRuntime(): int { return 120; }
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
		$question = trim((string)($input['input'] ?? ''));
		if ($question === '') {
			$question = 'Beschreibe die Bilder und nenne die wichtigsten erkennbaren Details.';
		}
		$rawIds = $input['images'] ?? [];
		if (!is_array($rawIds)) $rawIds = [$rawIds];
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => is_numeric($id) ? (int)$id : 0, array_slice($rawIds, 0, self::MAX_IMAGES)),
			static fn(int $id): bool => $id > 0,
		)));
		if ($ids === []) {
			throw new RuntimeException('No input images');
		}

		$home = $this->rootFolder->getUserFolder($userId);
		$images = [];
		$mimes = [];
		$totalBytes = 0;
		$errors = [];
		foreach ($ids as $index => $id) {
			$reportProgress(min(0.35, 0.08 + ($index / max(1, count($ids))) * 0.25));
			$node = $home->getById($id)[0] ?? null;
			if (!$node instanceof File) {
				$errors[] = 'Image ' . $id . ' is not available';
				continue;
			}
			$mime = strtolower((string)$node->getMimeType());
			if (!str_starts_with($mime, 'image/')) {
				$errors[] = $node->getName() . ' is not an image';
				continue;
			}
			$size = (int)$node->getSize();
			if ($size <= 0 || $size > self::MAX_IMAGE_BYTES || $totalBytes + $size > self::MAX_TOTAL_BYTES) {
				$errors[] = $node->getName() . ' exceeds the safe image size limit';
				continue;
			}
			$bytes = (string)$node->getContent();
			if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
				$errors[] = $node->getName() . ' could not be read safely';
				continue;
			}
			$images[] = base64_encode($bytes);
			$mimes[] = $mime;
			$totalBytes += strlen($bytes);
		}
		if ($images === []) {
			throw new RuntimeException(implode('; ', $errors) ?: 'No readable images');
		}

		$reportProgress(0.4);
		$result = $this->ollama->chat([
			['role' => 'system', 'content' => 'You are EVA vision. Answer the question using only what is visible in the supplied images. Do not invent identities or details. Answer in the same language as the question.'],
			['role' => 'user', 'content' => $question, 'images' => $images, 'image_mimes' => $mimes],
		], [], 120, static function (float $progress) use ($reportProgress): void {
			$reportProgress(0.4 + min(0.5, max(0.0, $progress)) * 0.9);
		});
		if (isset($result['error'])) {
			throw new RuntimeException((string)$result['error']);
		}
		$answer = trim((string)($result['answer'] ?? ''));
		if ($answer === '') {
			throw new RuntimeException('The vision model returned no answer. Check that the selected model supports image analysis.');
		}
		$reportProgress(1.0);
		return ['output' => $answer];
	}
}
