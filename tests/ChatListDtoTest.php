<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\ChatListQuery;
use OCA\EvaAi\Dto\ChatListResponse;
use PHPUnit\Framework\TestCase;

final class ChatListDtoTest extends TestCase {
	public function testNormalizesSearchAndDefaultsEmptySearchToNull(): void {
		self::assertSame('budget review', ChatListQuery::fromArray(['search' => '  budget review  '])->search);
		self::assertNull(ChatListQuery::fromArray([])->search);
		self::assertNull(ChatListQuery::fromArray(['search' => " \t"])->search);
	}

	public function testRejectsNonStringSearch(): void {
		$this->expectException(InvalidArgumentException::class);
		ChatListQuery::fromArray(['search' => ['unexpected']]);
	}

	public function testPreservesTypedChatSummaryAndOptionalSearchFields(): void {
		$chat = [
			'id' => 'c1', 'title' => 'Planning', 'created' => 10, 'updated' => 20,
			'count' => 2, 'bookmarkedCount' => 1, 'trimmed' => 0, 'pinned' => true,
			'folder' => 'Work', 'tags' => ['urgent'], 'archived' => false, 'scopePath' => '',
			'instructions' => '', 'persona' => 'default', 'snippet' => 'Budget review', 'matchCount' => 1,
		];

		$response = ChatListResponse::fromArray([$chat]);

		self::assertSame([$chat], $response->toArray());
	}

	public function testRejectsMalformedChatRowsAndNonListPayloads(): void {
		$this->expectException(InvalidArgumentException::class);
		ChatListResponse::fromArray([['id' => 'c1']]);
	}
}
