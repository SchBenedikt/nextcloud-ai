<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\OcrService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use RuntimeException;

/** Local OCR provider for image and PDF task inputs. */
final class EvaOcrProvider implements ISynchronousProvider {
	public function __construct(
		private AppConfig $appConfig,
		private OcrService $ocr,
		private IRootFolder $rootFolder,
		private IL10N $l,
	) {
	}

	public function getId(): string { return 'eva_ai:image2text:ocr'; }
	public function getName(): string { return $this->l->t('Eva (local)'); }
	public function getTaskTypeId(): string { return ImageToTextOpticalCharacterRecognition::ID; }
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
		if ($userId === null) {
			throw new RuntimeException('No user context');
		}
		$this->appConfig->setUserId($userId);
		$rawFiles = $input['input'] ?? [];
		if (!is_array($rawFiles)) {
			$rawFiles = [$rawFiles];
		}
		$home = $this->rootFolder->getUserFolder($userId);
		$nodes = [];
		foreach (array_slice($rawFiles, 0, 10) as $value) {
			$node = TaskProcessingFileInput::resolve($home, $value);
			if ($node instanceof File) $nodes[(int)$node->getId()] = $node;
		}
		$nodes = array_values($nodes);
		if ($nodes === []) {
			throw new RuntimeException('No input files');
		}

		$language = trim((string)$this->appConfig->get('ocr_language'));
		if ($language === '') {
			$language = 'eng';
		}
		$texts = [];
		$errors = [];
		foreach ($nodes as $index => $node) {
			TaskProcessingProgress::report($reportProgress, min(0.85, 0.1 + ($index / max(1, count($nodes))) * 0.7));
			try {
				$text = trim($this->ocr->extract((string)$node->getContent(), (string)$node->getMimeType(), $language));
				if ($text !== '') {
					$texts[] = '[' . $node->getName() . "]\n" . $text;
				} else {
					$errors[] = $node->getName() . ' contains no readable text';
				}
			} catch (\Throwable $e) {
				$errors[] = $node->getName() . ': ' . mb_substr($e->getMessage(), 0, 180);
			}
		}
		TaskProcessingProgress::report($reportProgress, 0.95);
		if ($texts === []) {
			throw new RuntimeException(implode('; ', $errors) ?: 'OCR returned no text');
		}
		return ['output' => $texts];
	}
}
