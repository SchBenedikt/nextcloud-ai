<?php

declare(strict_types=1);

namespace OCA\EvaAi\Http;

use OCP\AppFramework\Http\DataResponse;

/** Normalizes controller JSON errors while preserving success response bodies. */
final class ErrorDataResponse extends DataResponse {
	public function __construct(mixed $data = [], int $statusCode = 200, array $headers = []) {
		if (is_array($data)) {
			$data = ErrorPayload::normalize($data, $statusCode);
		}
		parent::__construct($data, $statusCode, $headers);
	}
}
