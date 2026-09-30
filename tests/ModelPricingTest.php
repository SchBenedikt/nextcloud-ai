<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\ModelPricing;
use PHPUnit\Framework\TestCase;

final class ModelPricingTest extends TestCase {
	public function testNormalizesUniqueNonNegativeRates(): void {
		$prices = ModelPricing::normalize([[
			'provider' => 'openai', 'model' => 'gpt-test', 'input_per_million' => '2.25', 'output_per_million' => '8.5',
		]]);

		self::assertSame([[
			'provider' => 'openai', 'model' => 'gpt-test', 'input_per_million' => 2.25, 'output_per_million' => 8.5,
		]], $prices);
	}

	public function testRejectsDuplicateInvalidAndExcessivePrices(): void {
		$base = ['provider' => 'openai', 'model' => 'gpt-test', 'input_per_million' => 1, 'output_per_million' => 2];
		self::assertNull(ModelPricing::normalize([$base, $base]));
		self::assertNull(ModelPricing::normalize([['provider' => 'openai', 'model' => 'gpt-test', 'input_per_million' => -1, 'output_per_million' => 2]]));
		self::assertNull(ModelPricing::normalize([['provider' => 'openai', 'model' => 'gpt-test', 'input_per_million' => 1, 'output_per_million' => INF]]));
		self::assertNull(ModelPricing::normalize(array_fill(0, 51, $base)));
	}

	public function testEstimatesCostFromInputAndOutputRatesSeparately(): void {
		$usage = ['input_tokens' => 250_000, 'output_tokens' => 100_000];
		$price = ['provider' => 'openai', 'model' => 'gpt-test', 'input_per_million' => 2.0, 'output_per_million' => 8.0];

		self::assertEqualsWithDelta(1.3, ModelPricing::estimate($usage, $price), 0.000001);
	}
}
