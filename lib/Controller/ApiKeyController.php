<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Dto\ChatRequest;
use OCA\EvaAi\Dto\ApiKeyCreateRequest;
use OCA\EvaAi\Dto\ApiKeyCreateResponse;
use OCA\EvaAi\Dto\ApiKeyListResponse;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\ApiKeyService;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\RagService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserManager;

/** User-facing API key management and the narrowly scoped external v1 API. */
final class ApiKeyController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ApiKeyService $keys,
		private AppConfig $config,
		private RagService $rag,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private ChatStore $chatStore,
		private DocumentMapper $documents,
		private ChunkMapper $chunks,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listKeys(): DataResponse {
		if ($this->userId === null) return new DataResponse(['error' => 'not_authenticated'], 401);
		return new DataResponse(ApiKeyListResponse::fromArray($this->keys->listForUser($this->userId))->toArray());
	}

	#[NoAdminRequired]
	public function createKey(): DataResponse {
		if ($this->userId === null) return new DataResponse(['error' => 'not_authenticated'], 401);
		try {
			$input = ApiKeyCreateRequest::fromArray($this->jsonBody());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
		if ($input->scope === 'admin' && !$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'admin_scope_requires_instance_admin'], 403);
		}
		try {
			$response = ApiKeyCreateResponse::fromArray($this->keys->create(
				$this->userId, $input->name, $input->scope, $input->expiresAt, $input->ipWhitelist,
			));
			return new DataResponse($response->toArray(), 201);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	#[NoAdminRequired]
	public function revokeKey(string $id): DataResponse {
		if ($this->userId === null) return new DataResponse(['error' => 'not_authenticated'], 401);
		return $this->keys->revoke($this->userId, $id)
			? new DataResponse(['ok' => true])
			: new DataResponse(['error' => 'key_not_found'], 404);
	}

	/** Bearer-key chat deliberately exposes no tool execution or admin actions. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function externalChat(): DataResponse {
		$key = $this->authorize('write');
		if ($key instanceof DataResponse) return $key;
		$userId = (string)$key['user_id'];
		$body = $this->jsonBody();
		$message = trim((string)($body['message'] ?? $this->request->getParam('message', '')));
		if ($message === '' || mb_strlen($message) > 50000) return new DataResponse(['error' => 'message_must_contain_1_to_50000_characters'], 400);
		$history = $body['history'] ?? $this->request->getParam('history', []);
		if (!is_array($history)) return new DataResponse(['error' => 'history_must_be_an_array'], 400);
		if (count($history) > 40) return new DataResponse(['error' => 'history_exceeds_40_messages'], 400);
		$history = array_values(array_map(static function (mixed $item): array {
			if (!is_array($item)) return ['role' => '', 'content' => ''];
			$role = in_array(($item['role'] ?? ''), ['user', 'assistant'], true) ? (string)$item['role'] : '';
			return ['role' => $role, 'content' => mb_substr((string)($item['content'] ?? ''), 0, 50000)];
		}, $history));
		foreach ($history as $messageInHistory) {
			if ($messageInHistory['role'] === '' || $messageInHistory['content'] === '') return new DataResponse(['error' => 'history_messages_require_user_or_assistant_role_and_content'], 400);
		}
		$this->config->setUserId($userId);
		try {
			$result = $this->rag->ask(new ChatRequest(userId: $userId, message: $message, history: $history, allowActions: false));
			return new DataResponse(['result' => $result]);
		} catch (\Throwable) {
			return new DataResponse(['error' => 'chat_failed'], 500);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function externalChats(): DataResponse {
		$key = $this->authorize('read');
		if ($key instanceof DataResponse) return $key;
		$includeArchived = filter_var($this->request->getParam('includeArchived', false), FILTER_VALIDATE_BOOLEAN);
		return new DataResponse(['chats' => $this->chatStore->list((string)$key['user_id'], null, $includeArchived)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function externalChatDetail(string $id): DataResponse {
		$key = $this->authorize('read');
		if ($key instanceof DataResponse) return $key;
		$chat = $this->chatStore->get((string)$key['user_id'], $id);
		if ($chat === null) return new DataResponse(['error' => 'chat_not_found'], 404);
		return new DataResponse(['chat' => [
			'id' => (string)($chat['id'] ?? ''), 'title' => (string)($chat['title'] ?? ''),
			'created' => (int)($chat['created'] ?? 0), 'updated' => (int)($chat['updated'] ?? 0),
			'messages' => array_map(static fn(array $message): array => [
				'role' => (string)($message['role'] ?? ''), 'text' => (string)($message['text'] ?? ''),
				'created' => (int)($message['created'] ?? 0),
			], is_array($chat['messages'] ?? null) ? $chat['messages'] : []),
		]]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function externalDocuments(): DataResponse {
		$key = $this->authorize('read');
		if ($key instanceof DataResponse) return $key;
		$limit = min(100, max(1, (int)$this->request->getParam('limit', 50)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));
		$rows = $this->documents->findByUser((string)$key['user_id'], null, $limit, $offset);
		return new DataResponse(['documents' => array_map(static fn($doc): array => [
			'id' => $doc->getId(), 'name' => $doc->getName(), 'path' => $doc->getPath(),
			'mime' => $doc->getMime(), 'size' => $doc->getSize(), 'chunks' => $doc->getChunkCount(),
			'source' => $doc->getSource(), 'indexed_at' => $doc->getIndexedAt(),
		], $rows), 'limit' => $limit, 'offset' => $offset]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function externalDocumentChunks(int $id): DataResponse {
		$key = $this->authorize('read');
		if ($key instanceof DataResponse) return $key;
		$document = $this->documents->findById($id);
		if ($document === null || $document->getUserId() !== (string)$key['user_id']) return new DataResponse(['error' => 'document_not_found'], 404);
		$limit = min(100, max(1, (int)$this->request->getParam('limit', 50)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));
		return new DataResponse([
			'document' => ['id' => $id, 'name' => $document->getName(), 'path' => $document->getPath()],
			'chunks' => $this->chunks->findByDocument($id, $limit, $offset), 'limit' => $limit, 'offset' => $offset,
		]);
	}

	/** @return array<string, mixed>|DataResponse */
	private function authorize(string $requiredScope): array|DataResponse {
		$authorization = trim((string)$this->request->getHeader('Authorization'));
		if (!preg_match('/^Bearer\s+(eva_sk_[A-Za-z0-9_-]{32,})$/i', $authorization, $match)) {
			return new DataResponse(['error' => 'invalid_api_key'], 401, ['WWW-Authenticate' => 'Bearer']);
		}
		try {
			$key = $this->keys->authenticate($match[1], (string)$this->request->getRemoteAddress());
		} catch (\OCA\EvaAi\Service\ApiKeyRateLimitException) {
			return new DataResponse(['error' => 'rate_limited'], 429, ['Retry-After' => '60']);
		} catch (\Throwable) {
			return new DataResponse(['error' => 'temporarily_unavailable'], 503);
		}
		if ($key === null) return new DataResponse(['error' => 'invalid_api_key_or_rate_limit'], 401, ['WWW-Authenticate' => 'Bearer']);
		$ranks = ['read' => 1, 'write' => 2, 'admin' => 3];
		if (($ranks[(string)$key['scope']] ?? 0) < ($ranks[$requiredScope] ?? 99)) {
			return new DataResponse(['error' => 'insufficient_scope', 'required' => $requiredScope], 403);
		}
		$user = $this->userManager->get((string)$key['user_id']);
		if ($user === null || !$user->isEnabled()) return new DataResponse(['error' => 'api_key_owner_unavailable'], 401);
		return $key;
	}

	private function jsonBody(): array {
		$raw = (string)file_get_contents('php://input');
		$data = $raw !== '' ? json_decode($raw, true) : [];
		return is_array($data) ? $data : [];
	}
}
