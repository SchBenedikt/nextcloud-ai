<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller\Traits;

use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\RequestRateLimiter;
use OCP\AppFramework\Http\DataResponse;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/** Shared bounded request admission for chat endpoints. */
trait RequestLimits {
	private function consumeRequestLimit(
		string $user,
		string $bucket,
		int $max,
		?RequestRateLimiter $limiter,
		ICacheFactory $cacheFactory,
		LoggerInterface $logger,
	): ?DataResponse {
		if ($limiter !== null) {
			$count = $limiter->consume($user, $bucket, 60, 'eva_ai_rate');
			if ($count === null) {
				return null;
			}
			if ($count > $max) {
				return new ErrorDataResponse(
					['error' => 'rate_limited', 'message' => 'Too many requests. Please retry shortly.'],
					429,
					['Retry-After' => '60'],
				);
			}
			return null;
		}

		try {
			$cache = $cacheFactory->createLocking('eva_ai_rate');
			$window = intdiv(time(), 60);
			$key = 'v1:' . hash('sha256', $user) . ':' . $bucket . ':' . $window;
			$cache->add($key, 0, 61);
			$count = $cache->inc($key);
			if (is_int($count) && $count > $max) {
				return new ErrorDataResponse(
					['error' => 'rate_limited', 'message' => 'Too many requests. Please retry shortly.'],
					429,
					['Retry-After' => '60'],
				);
			}
		} catch (\Throwable $e) {
			// A missing cache backend must not make chat unavailable.
			$logger->debug('eva_ai: rate limiter unavailable', ['exception' => $e]);
		}
		return null;
	}
}
