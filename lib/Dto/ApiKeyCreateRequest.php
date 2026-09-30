<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dto;

use InvalidArgumentException;

/** Validated input for creating a user API key. */
final class ApiKeyCreateRequest {
	/** @param list<string> $ipWhitelist */
	private function __construct(
		public readonly string $name,
		public readonly string $scope,
		public readonly ?int $expiresAt,
		public readonly array $ipWhitelist,
	) {
	}

	/** @param array<string,mixed> $input */
	public static function fromArray(array $input): self {
		$name = $input['name'] ?? '';
		if (!is_string($name)) {
			throw new InvalidArgumentException('Key name must contain 1 to 80 characters.');
		}
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 80) {
			throw new InvalidArgumentException('Key name must contain 1 to 80 characters.');
		}

		$scope = $input['scope'] ?? 'read';
		if (!is_string($scope) || !in_array($scope, ['read', 'write', 'admin'], true)) {
			throw new InvalidArgumentException('Scope must be read, write or admin.');
		}

		$expiresAt = null;
		$rawExpiry = $input['expiresAt'] ?? null;
		if ($rawExpiry !== null && $rawExpiry !== '') {
			if (is_int($rawExpiry)) {
				$expiresAt = $rawExpiry;
			} elseif (is_string($rawExpiry) && preg_match('/^[0-9]+$/D', $rawExpiry) === 1) {
				$expiresAt = filter_var($rawExpiry, FILTER_VALIDATE_INT);
				if ($expiresAt === false) {
					throw new InvalidArgumentException('invalid_expiration');
				}
			} else {
				throw new InvalidArgumentException('invalid_expiration');
			}
		}

		$rawIps = $input['ipWhitelist'] ?? [];
		if (is_string($rawIps)) {
			$rawIps = preg_split('/[,\s]+/', trim($rawIps), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		}
		if (!is_array($rawIps) || !array_is_list($rawIps)) {
			throw new InvalidArgumentException('invalid_ip_whitelist');
		}
		foreach ($rawIps as $ip) {
			if (!is_string($ip)) {
				throw new InvalidArgumentException('invalid_ip_whitelist');
			}
		}

		return new self($name, $scope, $expiresAt, array_values($rawIps));
	}
}
