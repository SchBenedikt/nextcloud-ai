<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed result returned by GET /api/file-context/status. */
final class FileContextStatusResponse {
	/** @param list<int> $indexed @param list<int> $missing @param list<array{fileId:int,name:string,path:string}> $files */
	private function __construct(
		public readonly array $indexed,
		public readonly array $missing,
		public readonly array $files,
	) {
	}

	/** @param list<int> $requested @param list<array{fileId:int,name:string,path:string}> $files */
	public static function forRequestedFiles(array $requested, array $files): self {
		$indexed = array_values(array_map(static fn(array $file): int => $file['fileId'], $files));
		return self::fromArray([
			'indexed' => $indexed,
			'missing' => array_values(array_diff($requested, $indexed)),
			'files' => $files,
		]);
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		$indexed = $data['indexed'] ?? null;
		$missing = $data['missing'] ?? null;
		$files = $data['files'] ?? null;
		if (!is_array($indexed) || !is_array($missing) || !is_array($files)) {
			throw new InvalidArgumentException('File context response requires indexed, missing, and files lists');
		}
		foreach ([$indexed, $missing] as $ids) {
			foreach ($ids as $id) {
				if (!is_int($id) || $id < 1) throw new InvalidArgumentException('File context IDs must be positive integers');
			}
		}
		foreach ($files as $file) {
			if (!is_array($file) || !is_int($file['fileId'] ?? null) || $file['fileId'] < 1
				|| !is_string($file['name'] ?? null) || !is_string($file['path'] ?? null)) {
				throw new InvalidArgumentException('File context entries require a positive fileId, name, and path');
			}
		}
		return new self(array_values($indexed), array_values($missing), array_values($files));
	}

	/** @return array{indexed:list<int>,missing:list<int>,files:list<array{fileId:int,name:string,path:string}>} */
	public function toArray(): array {
		return ['indexed' => $this->indexed, 'missing' => $this->missing, 'files' => $this->files];
	}
}
