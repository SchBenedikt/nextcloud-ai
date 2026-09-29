<?php

declare(strict_types=1);

namespace OCA\EvaAi\Http;

/** Builds the stable error body returned by EVA's JSON API endpoints. */
final class ErrorPayload {
	/** @param array<string, mixed> $payload @return array{error: array{code:string,message:string}}|array<string,mixed> */
	public static function normalize(array $payload, int $statusCode): array {
		if (!array_key_exists('error', $payload)) {
			return $payload;
		}

		$error = $payload['error'];
		$providedMessage = $payload['message'] ?? null;
		if (is_array($error)) {
			$code = is_string($error['code'] ?? null) ? $error['code'] : '';
			$providedMessage = $error['message'] ?? $providedMessage;
			$error = $code;
		}

		$code = is_string($error) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $error) === 1
			? $error
			: self::codeForStatus($statusCode);
		$message = is_string($providedMessage) && trim($providedMessage) !== ''
			? trim($providedMessage)
			: (is_string($error) ? trim($error) : 'The request could not be completed.');

		$normalized = $payload;
		unset($normalized['message']);
		$normalized['error'] = ['code' => $code, 'message' => $message];
		return $normalized;
	}

	private static function codeForStatus(int $statusCode): string {
		return match ($statusCode) {
			400, 422 => 'invalid_request',
			401, 403 => 'unauthorized',
			404 => 'not_found',
			409 => 'conflict',
			429 => 'rate_limited',
			default => $statusCode >= 500 ? 'server_error' : 'request_failed',
		};
	}
}
