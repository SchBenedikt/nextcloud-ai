<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Dto\ChatTemplateImportRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\ChatStore;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Owner-scoped lifecycle operations for saved chat prompt templates. */
final class ChatTemplateController extends OCSController {
	use ReadsRequestParameters;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ChatStore $chatStore,
	) {
		parent::__construct($appName, $request);
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}

	private function chatErrorResponse(\Throwable $error): DataResponse {
		if ($error instanceof \OCA\EvaAi\Service\ChatStoreConflictException) {
			return new ErrorDataResponse(['error' => 'conflict', 'message' => $error->getMessage()], 409);
		}
		if ($error instanceof \OCA\EvaAi\Service\ChatStoreBusyException) {
			return new ErrorDataResponse(['error' => 'busy', 'message' => $error->getMessage()], 503);
		}
		$message = $error->getMessage();
		if (str_contains($message, 'Invalid EVA chat data') || str_contains($message, 'Invalid EVA folder registry')) {
			return new ErrorDataResponse([
				'error' => 'corrupt_store',
				'message' => 'The EVA chat storage for this user is corrupt and was preserved. An administrator can recover it with: occ eva_ai:repair-chats <user>',
			], 500);
		}
		return new ErrorDataResponse(['error' => 'Unable to persist chat data'], 500);
	}

	#[NoAdminRequired]
	public function templates(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try { return new ErrorDataResponse($this->chatStore->listTemplates($user)); }
		catch (\Throwable $error) { return $this->chatErrorResponse($error); }
	}

	#[NoAdminRequired]
	public function saveTemplate(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try { return new ErrorDataResponse($this->chatStore->saveTemplate($user, $this->requestBody())); }
		catch (\InvalidArgumentException $error) { return new ErrorDataResponse(['error' => $error->getMessage()], 400); }
		catch (\Throwable $error) { return $this->chatErrorResponse($error); }
	}

	#[NoAdminRequired]
	public function deleteTemplate(string $id): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			if (!$this->chatStore->deleteTemplate($user, $id)) return new ErrorDataResponse(['error' => 'Requested template was not found.'], 404);
			return new ErrorDataResponse(['ok' => true]);
		} catch (\Throwable $error) { return $this->chatErrorResponse($error); }
	}

	#[NoAdminRequired]
	public function exportTemplates(): DataResponse {
		return $this->templates();
	}

	#[NoAdminRequired]
	public function importTemplates(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$request = ChatTemplateImportRequest::fromArray(['templates' => $this->requestParam('templates')]);
			return new ErrorDataResponse(['imported' => $this->chatStore->importTemplates($user, $request->templates)]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function useTemplate(string $id): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$template = $this->chatStore->useTemplate($user, $id);
			return $template === null
				? new ErrorDataResponse(['error' => 'Requested template was not found.'], 404)
				: new ErrorDataResponse($template);
		} catch (\Throwable $error) { return $this->chatErrorResponse($error); }
	}
}
