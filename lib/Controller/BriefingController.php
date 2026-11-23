<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\AppConfig;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Owner-scoped history and queue admission for scheduled EVA briefings. */
final class BriefingController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private AppConfig $config,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
		$this->config->setUserId($this->userId);
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}

	#[NoAdminRequired]
	public function briefingHistory(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		$this->config->setUserId($user);
		$history = json_decode($this->config->get('proactive_schedule_history'), true);
		return new ErrorDataResponse(['items' => is_array($history) ? array_slice($history, -50) : []]);
	}

	#[NoAdminRequired]
	public function runBriefingNow(string $id): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) return new ErrorDataResponse(['error' => 'Invalid briefing id.'], 400);
		$this->config->setUserId($user);
		if ($this->config->get('proactive_enabled') !== '1') return new ErrorDataResponse(['error' => 'Enable scheduled briefings before running one.'], 409);
		$schedules = json_decode($this->config->get('proactive_schedules'), true);
		$matches = is_array($schedules) ? array_values(array_filter($schedules, static fn($item) => is_array($item) && ($item['id'] ?? '') === $id)) : [];
		$schedule = $matches[0] ?? null;
		if (!is_array($schedule) || ($schedule['enabled'] ?? true) !== true) return new ErrorDataResponse(['error' => 'The enabled briefing was not found.'], 404);
		try {
			$this->jobList->scheduleAfter(\OCA\EvaAi\BackgroundJob\ProactiveBriefingJob::class, time() + 1, ['userId' => $user, 'briefingId' => $id, 'manual' => true]);
			return new ErrorDataResponse(['ok' => true, 'queued' => true]);
		} catch (\Throwable $error) {
			$this->logger->warning('eva_ai: could not queue a manual briefing', ['user' => $user, 'briefing' => $id, 'exception' => $error]);
			return new ErrorDataResponse(['error' => 'The briefing could not be queued.'], 503);
		}
	}
}
