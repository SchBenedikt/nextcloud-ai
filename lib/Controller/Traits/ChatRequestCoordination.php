<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller\Traits;

use OCA\EvaAi\Service\LockGuard;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/** Shares the per-user model-work claim across chat request controllers. */
trait ChatRequestCoordination {
	private function acquireChatRequestSlot(string $user, ILockingProvider $lockingProvider, LoggerInterface $logger): ?string {
		$path = LockGuard::chatRequestLockPath($user);
		try {
			$lockingProvider->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE, 'EVA chat request');
			return $path;
		} catch (\OCP\Lock\LockedException) {
			return null;
		} catch (\Throwable $e) {
			$logger->warning('eva_ai: chat request coordination unavailable', ['exception_class' => get_class($e)]);
			throw $e;
		}
	}

	private function releaseChatRequestSlot(?string $path, ILockingProvider $lockingProvider, LoggerInterface $logger): void {
		if ($path === null) {
			return;
		}
		try {
			$lockingProvider->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable $e) {
			$logger->warning('eva_ai: chat request release failed', ['exception_class' => get_class($e)]);
		}
	}

	private function releasePhpSessionLock(): void {
		try {
			\OCP\Server::get(\OCP\ISession::class)->close();
		} catch (\Throwable) {
			// CLI/test surfaces may not have an active session.
		}
	}
}
