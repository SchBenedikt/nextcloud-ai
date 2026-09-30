<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use InvalidArgumentException;
use OCA\EvaAi\Dto\GeneratedImageListResponse;
use PHPUnit\Framework\TestCase;

final class GeneratedImageResponseTest extends TestCase {
	/** @return array{id:int,name:string,mime:string,size:int,mtime:int,previewUrl:string,downloadUrl:string} */
	private function image(): array {
		return [
			'id' => 42,
			'name' => 'eva-generated-20260930-120000-aabbccdde1.png',
			'mime' => 'image/png',
			'size' => 1024,
			'mtime' => 1790776800,
			'previewUrl' => '/apps/files/api/v1/preview/42?x=768&y=768',
			'downloadUrl' => 'https://cloud.example/ocs/v2.php/apps/eva_ai/api/images/42/download',
		];
	}

	public function testListResponseRoundTripsTypedImages(): void {
		$row = $this->image();
		$response = GeneratedImageListResponse::fromArray([$row]);

		self::assertSame(['images' => [$row]], $response->toArray());
	}

	public function testListResponseRejectsInvalidRowsAndFields(): void {
		foreach ([
			[['id' => 42]],
			[array_replace($this->image(), ['mime' => 'text/html'])],
			[array_replace($this->image(), ['name' => 'other-file.png'])],
			[array_replace($this->image(), ['size' => -1])],
			[array_replace($this->image(), ['previewUrl' => 'javascript:alert(1)'])],
			[array_replace($this->image(), ['downloadUrl' => '//attacker.example/image.png'])],
		] as $rows) {
			try {
				GeneratedImageListResponse::fromArray($rows);
				self::fail('Invalid gallery response was accepted.');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testListResponseRequiresAListOfImageObjects(): void {
		$this->expectException(InvalidArgumentException::class);
		GeneratedImageListResponse::fromArray(['image' => $this->image()]);
	}
}
