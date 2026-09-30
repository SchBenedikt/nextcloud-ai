<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\ChatTemplateImportRequest;
use PHPUnit\Framework\TestCase;

final class ChatTemplateImportRequestTest extends TestCase {
	public function testAcceptsTemplateListAndIgnoresNonObjectItems(): void {
		$request = ChatTemplateImportRequest::fromArray([
			'templates' => [
				['name' => 'Daily brief', 'body' => 'Summarize {topic}'],
				'not an object',
				['name' => 'Meeting notes', 'body' => 'Extract decisions'],
			],
		]);

		self::assertSame([
			['name' => 'Daily brief', 'body' => 'Summarize {topic}'],
			['name' => 'Meeting notes', 'body' => 'Extract decisions'],
		], $request->templates);
	}

	public function testCapsImportsAtOneHundredEntries(): void {
		$templates = array_map(static fn(int $i): array => ['name' => 'Template ' . $i], range(0, 110));
		$request = ChatTemplateImportRequest::fromArray(['templates' => $templates]);

		self::assertCount(100, $request->templates);
		self::assertSame('Template 99', $request->templates[99]['name']);
	}

	public function testRejectsMissingOrNonListTemplateData(): void {
		foreach ([[], ['templates' => 'invalid'], ['templates' => ['named' => ['name' => 'Daily brief']]]] as $input) {
			try {
				ChatTemplateImportRequest::fromArray($input);
				self::fail('Invalid template list should be rejected');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}
}
