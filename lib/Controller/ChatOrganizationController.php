<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Dto\ChatFolderRequest;
use OCA\EvaAi\Dto\ChatMetadataRequest;
use OCA\EvaAi\Dto\ChatTitleRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Owner-scoped chat labels, organization metadata, and folder operations. */
final class ChatOrganizationController extends OCSController {
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
	public function chatTitle(string $id): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			if ($this->chatStore->get($user, $id) === null) {
				return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
			}
			$request = ChatTitleRequest::update(['title' => $this->requestParam('title')]);
			$this->chatStore->setTitle($user, $id, $request->title);
			return new ErrorDataResponse(['ok' => true]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function chatMeta(string $id): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$meta = ChatMetadataRequest::fromArray($this->requestBody())->metadata;
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}
		if (isset($meta['persona']) && !array_key_exists($meta['persona'], RagService::PERSONAS)) {
			$meta['persona'] = '';
		}
		try {
			if (!$this->chatStore->setMeta($user, $id, $meta)) {
				return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
			}
			return new ErrorDataResponse(['ok' => true, 'rev' => (int)($this->chatStore->getChat($user, $id)['rev'] ?? 0)]);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function folders(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			return new ErrorDataResponse($this->chatStore->listFolders($user));
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function createFolder(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$request = ChatFolderRequest::create(['name' => $this->requestParam('name')]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}
		try {
			return new ErrorDataResponse($this->chatStore->createFolder($user, $request->name));
		} catch (\Throwable) {
			return new ErrorDataResponse(['error' => 'Unable to create folder'], 500);
		}
	}

	#[NoAdminRequired]
	public function renameFolder(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$request = ChatFolderRequest::rename(['from' => $this->requestParam('from'), 'to' => $this->requestParam('to')]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}
		try {
			if (!$this->chatStore->renameFolder($user, $request->from, $request->to)) {
				return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
			}
			return new ErrorDataResponse(['ok' => true]);
		} catch (\Throwable) {
			return new ErrorDataResponse(['error' => 'Unable to rename folder'], 500);
		}
	}

	#[NoAdminRequired]
	public function setFolderColor(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$request = ChatFolderRequest::setColor(['name' => $this->requestParam('name'), 'color' => $this->requestParam('color', '')]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}
		try {
			if (!$this->chatStore->setFolderColor($user, $request->name, $request->color)) {
				return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
			}
			return new ErrorDataResponse(['ok' => true]);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function deleteFolderRest(): DataResponse {
		return $this->deleteFolder();
	}

	#[NoAdminRequired]
	public function deleteFolder(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$request = ChatFolderRequest::delete(['name' => $this->requestParam('name')]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}
		try {
			if (!$this->chatStore->deleteFolder($user, $request->name)) {
				return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
			}
			return new ErrorDataResponse(['ok' => true]);
		} catch (\Throwable) {
			return new ErrorDataResponse(['error' => 'Unable to delete folder'], 500);
		}
	}
}
