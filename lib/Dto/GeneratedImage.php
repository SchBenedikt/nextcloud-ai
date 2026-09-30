<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed representation of one generated image returned by the gallery API. */
final class GeneratedImage {
	private function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $mime,
		public readonly int $size,
		public readonly int $mtime,
		public readonly string $previewUrl,
		public readonly string $downloadUrl,
	) {
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		if (!isset($data['id']) || !is_int($data['id']) || $data['id'] < 1) {
			throw new InvalidArgumentException('id must be a positive integer');
		}
		foreach (['name', 'mime', 'previewUrl', 'downloadUrl'] as $field) {
			if (!isset($data[$field]) || !is_string($data[$field])) {
				throw new InvalidArgumentException($field . ' must be a string');
			}
		}
		if (!preg_match('/^eva-generated-[a-zA-Z0-9-]+\.(?:png|jpe?g|webp)$/i', $data['name'])) {
			throw new InvalidArgumentException('name must be a generated image filename');
		}
		if (!in_array($data['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
			throw new InvalidArgumentException('mime must be a supported image type');
		}
		foreach (['size', 'mtime'] as $field) {
			if (!isset($data[$field]) || !is_int($data[$field]) || $data[$field] < 0) {
				throw new InvalidArgumentException($field . ' must be a non-negative integer');
			}
		}
		foreach (['previewUrl', 'downloadUrl'] as $field) {
			$url = $data[$field];
			$parts = parse_url($url);
			if ($url === '' || preg_match('/[\x00-\x20]/', $url) || $parts === false
				|| isset($parts['user']) || isset($parts['pass'])
				|| (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))
				|| (!isset($parts['scheme']) && (!str_starts_with($url, '/') || str_starts_with($url, '//')))) {
				throw new InvalidArgumentException($field . ' must be a local path or HTTP URL');
			}
		}

		return new self($data['id'], $data['name'], $data['mime'], $data['size'], $data['mtime'], $data['previewUrl'], $data['downloadUrl']);
	}

	/** @return array{id:int,name:string,mime:string,size:int,mtime:int,previewUrl:string,downloadUrl:string} */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'mime' => $this->mime,
			'size' => $this->size,
			'mtime' => $this->mtime,
			'previewUrl' => $this->previewUrl,
			'downloadUrl' => $this->downloadUrl,
		];
	}
}
