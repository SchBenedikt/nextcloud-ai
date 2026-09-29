<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatTitleRequest;
use PHPUnit\Framework\TestCase;

final class ChatTitleRequestTest extends TestCase {
	public function testCreateAllowsAnOmittedTitleAndTrimsProvidedText(): void {
		self::assertSame('', ChatTitleRequest::create([])->title);
		self::assertSame('Project notes', ChatTitleRequest::create(['title' => ' Project notes '])->title);
	}

	public function testUpdateRequiresAStringAndNonEmptyTitle(): void {
		self::assertSame('Project notes', ChatTitleRequest::update(['title' => ' Project notes '])->title);
		foreach ([['title' => []], ['title' => '  ']] as $input) {
			try {
				ChatTitleRequest::update($input);
				self::fail('Expected invalid title to be rejected.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
