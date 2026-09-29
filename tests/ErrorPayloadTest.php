<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Http\ErrorPayload;
use PHPUnit\Framework\TestCase;

final class ErrorPayloadTest extends TestCase {
	public function testHumanErrorsGetStableStatusBasedCodes(): void {
		self::assertSame(
			['error' => ['code' => 'not_found', 'message' => 'Document not found']],
			ErrorPayload::normalize(['error' => 'Document not found'], 404),
		);
	}

	public function testMachineCodeAndHumanMessageAreBothPreserved(): void {
		self::assertSame(
			['ok' => false, 'validationErrors' => ['wait'], 'error' => ['code' => 'busy', 'message' => 'Another request is running.']],
			ErrorPayload::normalize(['ok' => false, 'validationErrors' => ['wait'], 'error' => 'busy', 'message' => 'Another request is running.'], 503),
		);
	}

	public function testAlreadyNormalizedErrorsStayStable(): void {
		$payload = ['error' => ['code' => 'conflict', 'message' => 'Reload and try again.']];
		self::assertSame($payload, ErrorPayload::normalize($payload, 409));
	}

	public function testSuccessPayloadsAreUnchanged(): void {
		$payload = ['ok' => true, 'items' => [1, 2]];
		self::assertSame($payload, ErrorPayload::normalize($payload, 200));
	}
}
