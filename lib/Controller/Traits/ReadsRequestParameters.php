<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller\Traits;

/** Read framework parameters first and decode a JSON body at most once. */
trait ReadsRequestParameters {
	private ?array $bodyParams = null;

	private function requestParam(string $key, mixed $default = null): mixed {
		$value = $this->request->getParam($key, null);
		if ($value !== null) {
			return $value;
		}
		$body = $this->requestBody();
		return array_key_exists($key, $body) ? $body[$key] : $default;
	}

	private function requestBody(): array {
		if ($this->bodyParams !== null) {
			return $this->bodyParams;
		}
		$raw = (string)file_get_contents('php://input');
		$decoded = $raw !== '' ? json_decode($raw, true) : null;
		$this->bodyParams = is_array($decoded) ? $decoded : [];
		return $this->bodyParams;
	}
}
