<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Http\ErrorDataResponse;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\TaskProcessing\IManager;

/** Read-only status for EVA's native Nextcloud Assistant providers. */
final class TaskProcessingAdminController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private IManager $taskProcessingManager,
	) {
		parent::__construct($appName, $request);
	}

	#[AdminRequired]
	public function status(): DataResponse {
		// Nextcloud 31 added the showDisabled argument. On newer cores, query
		// both views so a disabled type with an assigned provider is visible and
		// can be distinguished from a type with no usable assignment. Keep the
		// Nextcloud 30 call compatible with its zero-argument API.
		$taskTypesMethod = new \ReflectionMethod($this->taskProcessingManager, 'getAvailableTaskTypes');
		$canShowDisabled = $taskTypesMethod->getNumberOfParameters() > 0;
		$definitions = $this->taskProcessingManager->getAvailableTaskTypes();
		$allDefinitions = $canShowDisabled
			? $this->taskProcessingManager->getAvailableTaskTypes(true)
			: $definitions;
		$enabledTypes = array_fill_keys(array_keys($definitions), true);
		$rows = [];

		foreach ($this->taskProcessingManager->getProviders() as $provider) {
			$providerId = $provider->getId();
			if (!str_starts_with($providerId, 'eva_ai:')) {
				continue;
			}

			$taskTypeId = $provider->getTaskTypeId();
			$definition = $allDefinitions[$taskTypeId] ?? $definitions[$taskTypeId] ?? null;
			$assignmentKnown = false;
			$preferredProviderId = null;
			$preferredProviderName = null;
			try {
				$preferredProvider = $this->taskProcessingManager->getPreferredProvider($taskTypeId);
				$preferredProviderId = $preferredProvider->getId();
				$preferredProviderName = $preferredProvider->getName();
				$assignmentKnown = true;
			} catch (\OCP\TaskProcessing\Exception\Exception) {
				// Keep the state unknown; a lookup error must not look like "not selected".
			}

			$rows[] = [
				'taskTypeId' => $taskTypeId,
				'taskTypeName' => is_array($definition) ? (string)$definition['name'] : $taskTypeId,
				'providerId' => $providerId,
				'providerName' => $provider->getName(),
				'enabled' => isset($enabledTypes[$taskTypeId]),
				'enabledKnown' => isset($allDefinitions[$taskTypeId]) || isset($enabledTypes[$taskTypeId]),
				'assignmentKnown' => $assignmentKnown,
				'evaPreferred' => $assignmentKnown && $preferredProviderId === $providerId,
				'preferredProviderName' => $preferredProviderName,
			];
		}

		usort($rows, static fn (array $a, array $b): int =>
			strnatcasecmp($a['taskTypeName'], $b['taskTypeName']) ?: strcmp($a['taskTypeId'], $b['taskTypeId'])
		);

		return new ErrorDataResponse(['taskTypes' => $rows]);
	}
}
