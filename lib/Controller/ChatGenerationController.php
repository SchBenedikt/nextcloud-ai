<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ChatContextResolver;
use OCA\EvaAi\Controller\Traits\ChatRequestCoordination;
use OCA\EvaAi\Controller\Traits\ReadsRequestParameters;
use OCA\EvaAi\Controller\Traits\RequestLimits;
use OCA\EvaAi\Dto\ChatCompletionRequest;
use OCA\EvaAi\Dto\ChatRegenerateRequest;
use OCA\EvaAi\Dto\ChatRequest;
use OCA\EvaAi\Http\ErrorDataResponse;
use OCA\EvaAi\Http\StreamTraversableResponse;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\RequestRateLimiter;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/** Owner-scoped synchronous chat completion over EVA's RAG service. */
final class ChatGenerationController extends OCSController {
	use ReadsRequestParameters;
	use RequestLimits;
	use ChatRequestCoordination;
	use ChatContextResolver;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private AppConfig $config,
		private RagService $ragService,
		private ChatStore $chatStore,
		private ILockingProvider $lockingProvider,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private ?RequestRateLimiter $requestRateLimiter = null,
	) {
		parent::__construct($appName, $request);
		$this->config->setUserId($this->userId);
	}

	private function requireUser(): ?string {
		return $this->userId ?: null;
	}

	private function rateLimitResponse(string $user, string $bucket, int $max): ?DataResponse {
		return $this->consumeRequestLimit($user, $bucket, $max, $this->requestRateLimiter, $this->cacheFactory, $this->logger);
	}

	private function acquireChatSlot(string $user): ?string {
		return $this->acquireChatRequestSlot($user, $this->lockingProvider, $this->logger);
	}

	private function releaseChatSlot(?string $path): void {
		$this->releaseChatRequestSlot($path, $this->lockingProvider, $this->logger);
	}

	#[NoAdminRequired]
	public function chat(): DataResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return new ErrorDataResponse(['error' => 'Not logged in'], 401);
		}
		try {
			$request = ChatCompletionRequest::fromArray([
				'message' => $this->requestParam('message'),
				'history' => $this->requestParam('history', []),
				'chatId' => $this->requestParam('chatId'),
				'images' => $this->requestParam('images', []),
				'model' => $this->requestParam('model'),
			]);
		} catch (\InvalidArgumentException $error) {
			return new ErrorDataResponse(['error' => $error->getMessage()], 400);
		}

		if (($limited = $this->rateLimitResponse($user, 'chat', $this->config->getInt('rate_limit_chat_per_minute', 30))) !== null) {
			return $limited;
		}
		try {
			$chatSlot = $this->acquireChatSlot($user);
		} catch (\Throwable) {
			return new ErrorDataResponse(['error' => 'chat_unavailable', 'message' => 'Chat is temporarily unavailable. Please retry shortly.'], 503);
		}
		if ($chatSlot === null) {
			return new ErrorDataResponse(['error' => 'busy', 'message' => 'Another chat request is already running. Please retry shortly.'], 429, ['Retry-After' => '5']);
		}

		try {
			$this->releasePhpSessionLock();
			$custom = $this->customFor($user, $request->chatId);
			return new ErrorDataResponse($this->ragService->ask(new ChatRequest(
				userId: $user,
				message: $request->message,
				history: $request->history,
				scopePath: $this->scopePathFor($user, $request->chatId),
				images: $request->images,
				model: $request->model,
				instructions: $custom['instructions'],
				persona: $custom['persona'],
			)));
		} finally {
			$this->releaseChatSlot($chatSlot);
		}
	}

	/** Stream an edited or regenerated answer while preserving stored history until save. */
	#[NoAdminRequired]
	public function chatRegenerate(string $id): StreamTraversableResponse {
		$headers = [
			'Content-Type' => 'application/x-ndjson',
			'Cache-Control' => 'no-cache, no-store, must-revalidate',
			'X-Accel-Buffering' => 'no',
		];
		$user = $this->requireUser();
		if ($user === null) {
			$body = json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
			return new StreamTraversableResponse(new \ArrayIterator([$body]), 401, $headers);
		}
		try {
			$request = ChatRegenerateRequest::fromArray([
				'messageIndex' => $this->requestParam('messageIndex', -1),
				'message' => $this->requestParam('message'),
				'rev' => $this->requestParam('rev'),
			]);
		} catch (\InvalidArgumentException $error) {
			$body = json_encode(['type' => 'error', 'message' => $error->getMessage()]) . "\n";
			return new StreamTraversableResponse(new \ArrayIterator([$body]), 400, $headers);
		}

		$this->releasePhpSessionLock();
		$result = $this->chatStore->beginRegenerate($user, $id, $request->messageIndex, $request->message, $request->revision);
		if (!($result['ok'] ?? false)) {
			$error = (string)($result['error'] ?? 'invalid');
			if ($error === 'conflict') {
				$body = json_encode(['type' => 'error', 'message' => 'This chat was modified in another tab - reload to continue']) . "\n";
				return new StreamTraversableResponse(new \ArrayIterator([$body]), 200, $headers);
			}
			if ($error === 'not_found') {
				$body = json_encode(['type' => 'error', 'message' => 'Chat not found']) . "\n";
				return new StreamTraversableResponse(new \ArrayIterator([$body]), 404, $headers);
			}
			$body = json_encode(['type' => 'error', 'message' => 'A valid user message index and non-empty message are required']) . "\n";
			return new StreamTraversableResponse(new \ArrayIterator([$body]), 400, $headers);
		}

		$chat = $this->chatStore->getChat($user, $id);
		$messages = $chat['messages'] ?? [];
		$history = [];
		for ($index = 0; $index < $result['messageIndex']; $index++) {
			$message = $messages[$index];
			$history[] = ['role' => $message['role'] ?? 'user', 'content' => $message['text'] ?? ''];
		}
		$generation = $this->ragService->askStream(
			$user,
			$result['targetText'],
			$history,
			trim((string)($chat['scopePath'] ?? '')),
			trim((string)($chat['instructions'] ?? '')),
			trim((string)($chat['persona'] ?? '')),
		);
		$revision = (int)$result['rev'];
		$stream = (static function () use ($generation, $revision): \Generator {
			yield json_encode(['type' => 'regenerate', 'rev' => $revision]) . "\n";
			yield from $generation;
		})();
		return new StreamTraversableResponse($stream, 200, $headers);
	}
}
