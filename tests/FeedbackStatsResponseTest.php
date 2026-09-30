<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\FeedbackStatsResponse;
use PHPUnit\Framework\TestCase;

final class FeedbackStatsResponseTest extends TestCase {
	public function testPreservesThePublicFeedbackCounters(): void {
		$data = ['helpful' => 2, 'notHelpful' => 1, 'bookmarked' => 5];
		self::assertSame($data, FeedbackStatsResponse::fromArray($data)->toArray());
	}

	public function testRejectsMissingNonIntegerAndNegativeCounters(): void {
		foreach ([
			['helpful' => 0, 'notHelpful' => 0],
			['helpful' => 0, 'notHelpful' => '1', 'bookmarked' => 0],
			['helpful' => -1, 'notHelpful' => 0, 'bookmarked' => 0],
		] as $data) {
			try {
				FeedbackStatsResponse::fromArray($data);
				self::fail('Expected malformed feedback stats to be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
