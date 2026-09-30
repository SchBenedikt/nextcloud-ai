<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input shared by synchronous and streamed chat endpoints. */
final class ChatCompletionRequest {
	private const MAX_IMAGES = 4;
	private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;
	private const MAX_IMAGE_BASE64_BYTES = 5592408;
	private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

	/** @param list<array{role:string,content:string}> $history @param list<array{name:string,mime:string,data:string}> $images */
	private function __construct(
		public readonly string $message,
		public readonly array $history,
		public readonly ?string $chatId,
		public readonly array $images,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$message = $input['message'] ?? null;
		if (!is_string($message) || trim($message) === '') {
			throw new InvalidArgumentException('Empty message');
		}
		$message = trim($message);
		if (mb_strlen($message) > 50000) {
			throw new InvalidArgumentException('Message exceeds the maximum length of 50,000 characters.');
		}

		$history = $input['history'] ?? [];
		if (is_string($history)) {
			$decoded = json_decode($history, true);
			if (!is_array($decoded)) {
				throw new InvalidArgumentException('history must be a list of chat messages');
			}
			$history = $decoded;
		}
		if (!is_array($history) || !array_is_list($history)) {
			throw new InvalidArgumentException('history must be a list of chat messages');
		}
		$cleanHistory = [];
		foreach ($history as $item) {
			if (!is_array($item)
				|| !in_array($item['role'] ?? null, ['user', 'assistant'], true)
				|| !is_string($item['content'] ?? null)) {
				throw new InvalidArgumentException('history contains an invalid chat message');
			}
			$cleanHistory[] = ['role' => $item['role'], 'content' => $item['content']];
		}

		$chatId = $input['chatId'] ?? null;
		if ($chatId !== null && !is_string($chatId)) {
			throw new InvalidArgumentException('chatId must be a string');
		}

		$images = $input['images'] ?? [];
		if (is_string($images)) {
			$decoded = json_decode($images, true);
			if (!is_array($decoded)) throw new InvalidArgumentException('images must be a list of image attachments');
			$images = $decoded;
		}
		if (!is_array($images) || !array_is_list($images) || count($images) > self::MAX_IMAGES) {
			throw new InvalidArgumentException('Attach up to four supported images at a time.');
		}
		$cleanImages = [];
		$totalImageBytes = 0;
		$totalEncodedBytes = 0;
		foreach ($images as $image) {
			if (!is_array($image) || !is_string($image['mime'] ?? null) || !in_array($image['mime'], self::IMAGE_MIMES, true)
				|| !is_string($image['data'] ?? null) || $image['data'] === '') {
				throw new InvalidArgumentException('Each attachment must be a PNG, JPEG, or WebP image.');
			}
			$encodedBytes = strlen($image['data']);
			$totalEncodedBytes += $encodedBytes;
			if ($encodedBytes > self::MAX_IMAGE_BASE64_BYTES || $totalEncodedBytes > self::MAX_IMAGE_BASE64_BYTES) {
				throw new InvalidArgumentException('Attached images must total no more than 4 MB.');
			}
			$bytes = base64_decode($image['data'], true);
			if (!is_string($bytes) || $bytes === '') throw new InvalidArgumentException('An attached image is not valid base64 data.');
			$totalImageBytes += strlen($bytes);
			if ($totalImageBytes > self::MAX_IMAGE_BYTES) {
				throw new InvalidArgumentException('Attached images must total no more than 4 MB.');
			}
			if (!function_exists('getimagesizefromstring')) {
				throw new InvalidArgumentException('Image validation is unavailable on this server.');
			}
			$info = @getimagesizefromstring($bytes);
			$actualMime = is_array($info) ? (string)($info['mime'] ?? '') : '';
			$width = is_array($info) ? (int)($info[0] ?? 0) : 0;
			$height = is_array($info) ? (int)($info[1] ?? 0) : 0;
			if ($actualMime !== $image['mime'] || $width < 1 || $height < 1 || $width * $height > 25000000) {
				throw new InvalidArgumentException('An attached image is invalid or exceeds 25 megapixels.');
			}
			$name = $image['name'] ?? 'image';
			if (!is_string($name)) $name = 'image';
			$name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($name)) ?? ''), 0, 120) ?: 'image';
			$cleanImages[] = ['name' => $name, 'mime' => $image['mime'], 'data' => $image['data']];
		}
		return new self($message, $cleanHistory, $chatId, $cleanImages);
	}
}
