<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Small shared helpers for exact cosine ranking and persisted norm metadata. */
final class VectorNorm {
	public static function of(array $vector): ?float {
		if ($vector === []) return null;
		$sum = 0.0;
		foreach ($vector as $value) {
			if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) return null;
			$value = (float)$value;
			$sum += $value * $value;
		}
		if (!is_finite($sum) || $sum <= 0.0) return null;
		$norm = sqrt($sum);
		return is_finite($norm) && $norm > 0.0 ? $norm : null;
	}

	public static function fromJson(string $json): ?float {
		if ($json === '' || strlen($json) > 524288) return null;
		try {
			$vector = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return null;
		}
		return is_array($vector) && array_is_list($vector) ? self::of($vector) : null;
	}
}
