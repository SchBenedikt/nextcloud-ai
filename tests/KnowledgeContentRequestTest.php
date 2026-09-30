<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\KnowledgeContentRequest;
use PHPUnit\Framework\TestCase;

final class KnowledgeContentRequestTest extends TestCase {
	public function testDefaultsMissingContentToEmptyString(): void {
		self::assertSame('', KnowledgeContentRequest::fromArray([])->content);
	}

	public function testRetainsContentWithoutTrimming(): void {
		self::assertSame(" # Notes\n\nUseful context \n", KnowledgeContentRequest::fromArray(['content' => " # Notes\n\nUseful context \n"])->content);
	}

	public function testRejectsNonStringAndOversizedContent(): void {
		foreach ([['content' => []], ['content' => str_repeat('x', 60001)]] as $input) {
			try {
				KnowledgeContentRequest::fromArray($input);
				self::fail('Invalid knowledge content should be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
