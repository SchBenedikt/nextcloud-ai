<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\BackgroundJob\IndexRequestJob;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\LockGuard;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;

/** User-scoped indexing lifecycle and queue admission. */
final class IndexController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private AppConfig $config,
		private Indexer $indexer,
		private RagService $ragService,
		private IJobList $jobList,
		private ILockingProvider $lockingProvider,
		private LockGuard $lockGuard,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function resetIndexSnake(): DataResponse {
		return $this->resetIndex();
	}

	#[NoAdminRequired]
	public function resetIndex(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		$this->config->setUserId($user);
		$this->recoverStaleIndex();
		if ($this->config->get('index_running') === '1') return new ErrorDataResponse(['error' => 'Stop indexing before deleting the index.'], 409);
		$deleted = $this->indexer->reset($user);
		return new ErrorDataResponse(['result' => $deleted, 'status' => $this->ragService->buildStatus($user)]);
	}

	#[NoAdminRequired]
	public function startIndex(): DataResponse {
		return $this->queueIndex('all');
	}

	#[NoAdminRequired]
	public function startMailIndex(): DataResponse {
		return $this->queueIndex('mail');
	}

	#[NoAdminRequired]
	public function startMailIndexSnake(): DataResponse {
		return $this->startMailIndex();
	}

	/** Explicit start indexes only rooms the user belongs to. */
	#[NoAdminRequired]
	public function startTalkIndex(): DataResponse {
		return $this->queueIndex('talk');
	}

	#[NoAdminRequired]
	public function startTalkIndexSnake(): DataResponse {
		return $this->startTalkIndex();
	}

	#[NoAdminRequired]
	public function stopIndexSnake(): DataResponse {
		return $this->stopIndex();
	}

	#[NoAdminRequired]
	public function stopIndex(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		$this->config->setUserId($user);
		$this->recoverStaleIndex();
		if ($this->config->get('index_running') !== '1') return new ErrorDataResponse(['stopped' => true, 'status' => $this->ragService->buildStatus($user)]);
		// The worker owns the terminal transition after it observes this flag.
		$this->config->set('index_cancel_requested', '1');
		return new ErrorDataResponse(['stopped' => true, 'stopping' => true, 'status' => $this->ragService->buildStatus($user)]);
	}

	private function queueIndex(string $mode): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		$this->config->setUserId($user);
		$this->recoverStaleIndex();
		if ($this->config->get('index_running') === '1') {
			if ($this->config->get('index_cancel_requested') === '1') {
				try {
					$this->jobList->add(IndexRequestJob::class, ['userId' => $user, 'mode' => $mode, 'waitForCancellation' => true]);
					return new ErrorDataResponse(['queued' => true, 'waitingForStop' => true, 'mode' => $mode, 'status' => $this->ragService->buildStatus($user)]);
				} catch (\Throwable) {
					return new ErrorDataResponse(['error' => 'The follow-up index job could not be queued.'], 500);
				}
			}
			return new ErrorDataResponse(['queued' => false, 'alreadyRunning' => true, 'message' => 'Indexing is already running for this user.', 'status' => $this->ragService->buildStatus($user)]);
		}

		$runId = bin2hex(random_bytes(16));
		$lockPath = LockGuard::indexLockPath($user);
		try {
			$this->lockGuard->acquireIndexLock($user, $lockPath);
		} catch (\Throwable) {
			return new ErrorDataResponse(['queued' => false, 'error' => 'Indexing is currently locked by another worker. Please retry shortly.', 'status' => $this->ragService->buildStatus($user)], 409);
		}
		if (!$this->config->tryClaimIndex($user)) {
			$this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
			return new ErrorDataResponse(['queued' => false, 'error' => 'Indexing could not be claimed because another worker is active. Please retry shortly.', 'status' => $this->ragService->buildStatus($user)], 409);
		}
		try {
			$this->config->setUserId($user);
			$now = (string)time();
			$this->config->set('index_started', $now);
			$this->config->set('index_heartbeat', $now);
			$this->config->set('index_finished', '0');
			$this->config->set('last_index_error', '');
			$this->config->set('index_mode', $mode);
			$this->config->set('index_cancel_requested', '0');
			$this->config->set('index_run_id', $runId);
			$this->jobList->add(IndexRequestJob::class, ['userId' => $user, 'mode' => $mode, 'runId' => $runId]);
			$this->config->setIndexEnrolled($user, true);
			return new ErrorDataResponse(['queued' => true, 'mode' => $mode, 'status' => $this->ragService->buildStatus($user)]);
		} catch (\Throwable) {
			$this->config->setUserId($user);
			if ($this->config->get('index_run_id') === $runId) {
				$this->config->set('index_running', '0');
				$this->config->set('index_mode', 'idle');
				$this->config->set('index_run_id', '');
				$this->config->set('index_heartbeat', '');
			}
			return new ErrorDataResponse(['error' => 'The background index job could not be queued.'], 500);
		} finally {
			$this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function recoverStaleIndex(): void {
		$this->config->recoverAbandonedRun();
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}
}
