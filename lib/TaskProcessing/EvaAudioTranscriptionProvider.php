<?php

declare(strict_types=1);

namespace OCA\EvaAi\TaskProcessing;

use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\OpenAICompatible;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use RuntimeException;

/** Shared bounded file handling for audio transcription and subtitles. */
abstract class EvaAudioTranscriptionProvider implements ISynchronousProvider {
	private const MAX_BYTES = 25_000_000;
	private const LANGUAGE_RULE = 'same language as the audio';

	public function __construct(
		protected AppConfig $appConfig,
		protected OpenAICompatible $audio,
		protected IRootFolder $rootFolder,
		protected IL10N $l,
	) {
	}

	abstract protected function subtitles(): bool;

	public function getExpectedRuntime(): int { return 180; }
	public function getInputShapeEnumValues(): array { return []; }
	public function getInputShapeDefaults(): array { return []; }
	public function getOptionalInputShape(): array { return []; }
	public function getOptionalInputShapeEnumValues(): array { return []; }
	public function getOptionalInputShapeDefaults(): array { return []; }
	public function getOutputShapeEnumValues(): array { return []; }
	public function getOptionalOutputShape(): array { return []; }
	public function getOptionalOutputShapeEnumValues(): array { return []; }

	/** @return array{node:File,bytes:string,filename:string,mime:string} */
	protected function inputFile(?string $userId, array $input): array {
		if ($userId === null || $userId === '') throw new RuntimeException('No user context');
		$value = $input['input'] ?? null;
		$id = is_numeric($value) ? (int)$value : 0;
		if ($id <= 0) throw new RuntimeException('No input audio file');
		$node = $this->rootFolder->getUserFolder($userId)->getById($id)[0] ?? null;
		if (!$node instanceof File) throw new RuntimeException('The input audio file is not available.');
		$mime = strtolower((string)$node->getMimeType());
		if (!str_starts_with($mime, 'audio/') && !str_starts_with($mime, 'video/')) throw new RuntimeException('The input file is not audio or video.');
		$size = (int)$node->getSize();
		if ($size <= 0 || $size > self::MAX_BYTES) throw new RuntimeException('The audio file exceeds the safe 25 MB limit.');
		$bytes = (string)$node->getContent();
		if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new RuntimeException('The audio file could not be read safely.');
		return ['node' => $node, 'bytes' => $bytes, 'filename' => $node->getName(), 'mime' => $mime];
	}

	protected function outputFile(string $userId, string $content, string $extension, string $prefix = 'eva-subtitles'): int {
		$home = $this->rootFolder->getUserFolder($userId);
		if ($home->nodeExists('EVA')) {
			$node = $home->get('EVA');
			if (!$node instanceof Folder) throw new RuntimeException('The EVA folder exists but is not a folder.');
			$folder = $node;
		} else {
			$folder = $home->newFolder('EVA');
		}
		$name = $prefix . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
		return (int)$folder->newFile($name, $content)->getId();
	}
}
