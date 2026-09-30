<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\KnowledgeContentResponse;
use OCA\EvaAi\Dto\KnowledgeContentSaveResponse;
use PHPUnit\Framework\TestCase;

final class KnowledgeContentResponseTest extends TestCase {
	public function testKnowledgeReadResponseCountsUnicodeCharacters(): void {
		$response = KnowledgeContentResponse::fromContent('Grüße 👋');
		self::assertSame(['content' => 'Grüße 👋', 'length' => mb_strlen('Grüße 👋')], $response->toArray());
	}

	public function testKnowledgeReadResponseRejectsMismatchedLength(): void {
		$this->expectException(InvalidArgumentException::class);
		KnowledgeContentResponse::fromArray(['content' => 'abc', 'length' => 2]);
	}

	public function testKnowledgeSaveResponsePreservesItsPublicShape(): void {
		self::assertSame(['ok' => true, 'length' => 3], KnowledgeContentSaveResponse::saved('abc')->toArray());
	}
}
