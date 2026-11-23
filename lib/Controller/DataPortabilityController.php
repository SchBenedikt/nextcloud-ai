<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Dto\ChatImportRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\UserDataService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Owner-scoped export and additive restore of a user's EVA data. */
final class DataPortabilityController extends OCSController {
	use ReadsRequestParameters;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private UserDataService $userDataService,
		private ChatStore $chatStore,
	) {
		parent::__construct($appName, $request);
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}

	#[NoAdminRequired]
	public function exportData(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$response = new ErrorDataResponse($this->userDataService->export($user));
			$response->addHeader('Content-Disposition', 'attachment; filename="eva_ai_export_' . rawurlencode($user) . '.json"');
			return $response;
		} catch (\Throwable) {
			return new ErrorDataResponse(['error' => 'The EVA data export could not be created.'], 500);
		}
	}

	/** Add chats from an EVA archive without replacing existing conversations. */
	#[NoAdminRequired]
	public function importData(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$request = ChatImportRequest::fromArray($this->requestBody());
			$count = $this->chatStore->importAll($user, $request->chats);
			return new ErrorDataResponse(['ok' => true, 'imported' => $count]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		} catch (\OCA\EvaAi\Service\ChatStoreBusyException) {
			return new ErrorDataResponse(['error' => 'busy', 'message' => 'The chat history is busy. Please retry shortly.'], 503);
		} catch (\Throwable) {
			return new ErrorDataResponse(['error' => 'The chat archive could not be imported.'], 500);
		}
	}
}
