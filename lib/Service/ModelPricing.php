<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** User supplied token prices used only to estimate model spend in Metrics. */
final class ModelPricing {
	/** @return array{provider:string,model:string,input_per_million:float,output_per_million:float}|null */
	public static function find(array $pricing, string $provider, string $model): ?array {
		foreach ($pricing as $entry) {
			if (!is_array($entry)
				|| (string)($entry['provider'] ?? '') !== $provider
				|| (string)($entry['model'] ?? '') !== $model) {
				continue;
			}
			return [
				'provider' => $provider,
				'model' => $model,
				'input_per_million' => (float)$entry['input_per_million'],
				'output_per_million' => (float)$entry['output_per_million'],
			];
		}
		return null;
	}

	/** @param array<string,mixed> $usage */
	public static function estimate(array $usage, array $price): float {
		return ((int)($usage['input_tokens'] ?? 0) * $price['input_per_million']
			+ (int)($usage['output_tokens'] ?? 0) * $price['output_per_million']) / 1_000_000;
	}

	/** @return array<int,array{provider:string,model:string,input_per_million:float,output_per_million:float}>|null */
	public static function normalize(mixed $value): ?array {
		if (!is_array($value) || count($value) > 50) {
			return null;
		}
		$normalized = [];
		$seen = [];
		foreach ($value as $entry) {
			if (!is_array($entry)) return null;
			$provider = trim((string)($entry['provider'] ?? ''));
			$model = trim((string)($entry['model'] ?? ''));
			if ($provider === '' || strlen($provider) > 32 || preg_match('/^[A-Za-z0-9._-]+$/D', $provider) !== 1
				|| $model === '' || mb_strlen($model) > 128) return null;
			$key = $provider . "\0" . $model;
			if (isset($seen[$key])) return null;
			$seen[$key] = true;
			$rates = [];
			foreach (['input_per_million', 'output_per_million'] as $field) {
				$rate = $entry[$field] ?? null;
				if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate < 0 || (float)$rate > 10000) return null;
				$rates[$field] = round((float)$rate, 6);
			}
			$normalized[] = ['provider' => $provider, 'model' => $model] + $rates;
		}
		return $normalized;
	}
}
