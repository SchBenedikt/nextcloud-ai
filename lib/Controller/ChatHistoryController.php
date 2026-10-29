<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Dto\ChatListQuery;
use OCA\EvaAi\Dto\ChatListResponse;
use OCA\EvaAi\Dto\ChatTitleRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;

/** Owner-scoped chat history reads and lifecycle operations. */
final class ChatHistoryController extends OCSController {
	use ReadsRequestParameters;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ChatStore $chatStore,
		private IConfig $systemConfig,
	) {
		parent::__construct($appName, $request);
	}

	private function requireUser(): ?string {
		return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
	}

	/** Keep storage failures consistent across all chat-history operations. */
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
	public function chatPage(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		$offset = filter_var($this->requestParam('offset', 0), FILTER_VALIDATE_INT);
		$limit = filter_var($this->requestParam('limit', 50), FILTER_VALIDATE_INT);
		if ($offset === false || $limit === false || $offset < 0 || $limit < 1 || $limit > 100) {
			return new ErrorDataResponse(['error' => 'Invalid chat page.'], 400);
		}
		try {
			if ($this->requestParam('keyset', '0') === '1') {
				$updatedRaw = $this->requestParam('beforeUpdated', null);
				$chatIdRaw = $this->requestParam('beforeChatId', null);
				$beforeUpdated = $updatedRaw === null ? null : filter_var($updatedRaw, FILTER_VALIDATE_INT);
				if (($updatedRaw !== null && $beforeUpdated === false)
					|| ($chatIdRaw !== null && (!is_string($chatIdRaw) || $chatIdRaw === '' || strlen($chatIdRaw) > 128))
					|| (($updatedRaw === null) !== ($chatIdRaw === null))) {
					return new ErrorDataResponse(['error' => 'Invalid chat page cursor.'], 400);
				}
				$page = $this->chatStore->listSqlCursorPage($user, $beforeUpdated, $chatIdRaw, $limit);
				if ($page !== null) return new ErrorDataResponse($page);
			}
			return new ErrorDataResponse($this->chatStore->listPage($user, $offset, $limit, true));
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function chats(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$query = ChatListQuery::fromArray(['search' => $this->requestParam('search', '')]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}
		// Archived chats stay in this API response because the sidebar has a
		// separate archive section. The dashboard uses its own filtered query.
		try {
			$response = ChatListResponse::fromArray($this->chatStore->list($user, $query->search, true));
			return new ErrorDataResponse($response->toArray());
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function createChat(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$request = ChatTitleRequest::create(['title' => $this->requestParam('title', '')]);
			return new ErrorDataResponse($this->chatStore->create($user, $request->title));
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function deleteAllChats(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			return new ErrorDataResponse(['ok' => true, 'deleted' => $this->chatStore->deleteAll($user)]);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}

	#[NoAdminRequired]
	public function chatDetail(string $id): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			$chat = $this->chatStore->get($user, $id);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
		if ($chat === null) return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
		return new ErrorDataResponse($this->normalizeConnectorConfirmations($user, $chat));
	}

	/** Upgrade old connector confirmations in the response without mutating stored history. */
	private function normalizeConnectorConfirmations(string $user, array $chat): array {
		$connectors = json_decode($this->systemConfig->getUserValue($user, AppConfig::APP, 'external_connectors', '{}'), true);
		if (!is_array($connectors)) return $chat;
		foreach (($chat['messages'] ?? []) as $index => $message) {
			$confirmation = $message['confirmation'] ?? null;
			if (!is_array($confirmation) || ($confirmation['name'] ?? '') !== 'call_app_api') continue;
			$args = $confirmation['arguments'] ?? [];
			$alias = is_array($args) ? strtolower(trim((string)($args['app_id'] ?? ''))) : '';
			if ($alias === '' || !array_key_exists($alias, $connectors)) continue;
			$confirmation['name'] = 'call_external_connector';
			if (is_array($args)) {
				$args['id'] = $alias;
				unset($args['app_id']);
				$confirmation['arguments'] = $args;
			}
			$chat['messages'][$index]['confirmation'] = $confirmation;
		}
		return $chat;
	}

	#[NoAdminRequired]
	public function chatDelete(string $id): DataResponse {
		$user = $this->requireUser();
		if ($user === null) return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		try {
			if (!$this->chatStore->delete($user, $id)) {
				return new ErrorDataResponse(['error' => 'Requested resource was not found.'], 404);
			}
			return new ErrorDataResponse(['ok' => true]);
		} catch (\Throwable $error) {
			return $this->chatErrorResponse($error);
		}
	}
}
