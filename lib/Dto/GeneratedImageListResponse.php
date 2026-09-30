<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed list response for GET /api/images and POST /api/images/generate. */
final class GeneratedImageListResponse {
	/** @param list<GeneratedImage> $images */
	private function __construct(public readonly array $images) {
	}

	/** @param array<mixed> $rows */
	public static function fromArray(array $rows): self {
		if (!array_is_list($rows)) {
			throw new InvalidArgumentException('images must be a list');
		}
		$images = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				throw new InvalidArgumentException('each image must be an object');
			}
			$images[] = GeneratedImage::fromArray($row);
		}
		return new self($images);
	}

	/** @return array{images:list<array{id:int,name:string,mime:string,size:int,mtime:int,previewUrl:string,downloadUrl:string}>} */
	public function toArray(): array {
		return ['images' => array_map(static fn(GeneratedImage $image): array => $image->toArray(), $this->images)];
	}
}
