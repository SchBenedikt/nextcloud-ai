<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Typed representation of one row returned by the chat list API. */
final class ChatSummary {
	/** @param list<string> $tags */
	private function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly int $created,
		public readonly int $updated,
		public readonly int $count,
		public readonly int $bookmarkedCount,
		public readonly int $trimmed,
		public readonly bool $pinned,
		public readonly string $folder,
		public readonly array $tags,
		public readonly bool $archived,
		public readonly string $scopePath,
		public readonly string $instructions,
		public readonly string $persona,
		public readonly ?string $snippet,
		public readonly ?int $matchCount,
	) {
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		foreach (['id', 'title', 'folder', 'scopePath', 'instructions', 'persona'] as $field) {
			if (!isset($data[$field]) || !is_string($data[$field])) {
				throw new InvalidArgumentException($field . ' must be a string');
			}
		}
		foreach (['created', 'updated', 'count', 'bookmarkedCount', 'trimmed'] as $field) {
			if (!isset($data[$field]) || !is_int($data[$field])) {
				throw new InvalidArgumentException($field . ' must be an integer');
			}
		}
		foreach (['pinned', 'archived'] as $field) {
			if (!isset($data[$field]) || !is_bool($data[$field])) {
				throw new InvalidArgumentException($field . ' must be a boolean');
			}
		}
		if (!isset($data['tags']) || !is_array($data['tags']) || !array_is_list($data['tags'])) {
			throw new InvalidArgumentException('tags must be a list of strings');
		}
		foreach ($data['tags'] as $tag) {
			if (!is_string($tag)) {
				throw new InvalidArgumentException('tags must contain only strings');
			}
		}
		$snippet = $data['snippet'] ?? null;
		$matchCount = $data['matchCount'] ?? null;
		if ($snippet !== null && !is_string($snippet)) {
			throw new InvalidArgumentException('snippet must be a string');
		}
		if ($matchCount !== null && !is_int($matchCount)) {
			throw new InvalidArgumentException('matchCount must be an integer');
		}

		return new self(
			$data['id'], $data['title'], $data['created'], $data['updated'], $data['count'],
			$data['bookmarkedCount'], $data['trimmed'], $data['pinned'], $data['folder'],
			$data['tags'], $data['archived'], $data['scopePath'], $data['instructions'],
			$data['persona'], $snippet, $matchCount,
		);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$data = [
			'id' => $this->id,
			'title' => $this->title,
			'created' => $this->created,
			'updated' => $this->updated,
			'count' => $this->count,
			'bookmarkedCount' => $this->bookmarkedCount,
			'trimmed' => $this->trimmed,
			'pinned' => $this->pinned,
			'folder' => $this->folder,
			'tags' => $this->tags,
			'archived' => $this->archived,
			'scopePath' => $this->scopePath,
			'instructions' => $this->instructions,
			'persona' => $this->persona,
		];
		if ($this->snippet !== null) {
			$data['snippet'] = $this->snippet;
		}
		if ($this->matchCount !== null) {
			$data['matchCount'] = $this->matchCount;
		}
		return $data;
	}
}
