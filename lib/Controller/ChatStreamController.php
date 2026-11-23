<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCA\EvaAi\Controller\Traits\ChatContextResolver;
use OCA\EvaAi\Controller\Traits\ChatRequestCoordination;
use OCA\EvaAi\Controller\Traits\RequestLimits;
use OCA\EvaAi\Dto\ChatCompletionRequest;
use OCA\EvaAi\Dto\ChatRequest;
use OCA\EvaAi\Http\StreamTraversableResponse;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\RequestRateLimiter;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\OCSController;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/** Owns the authenticated NDJSON chat lifecycle and resource cleanup. */
final class ChatStreamController extends OCSController {
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
		private IAppManager $appManager,
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

	private function rateLimitResponse(string $user, string $bucket, int $max): ?\OCP\AppFramework\Http\DataResponse {
		return $this->consumeRequestLimit($user, $bucket, $max, $this->requestRateLimiter, $this->cacheFactory, $this->logger);
	}

	private function acquireChatSlot(string $user): ?string {
		return $this->acquireChatRequestSlot($user, $this->lockingProvider, $this->logger);
	}

	private function releaseChatSlot(?string $path): void {
		$this->releaseChatRequestSlot($path, $this->lockingProvider, $this->logger);
	}

	private function releaseSessionLock(): void {
		$this->releasePhpSessionLock();
	}

	#[NoAdminRequired]
	public function streamChat(): StreamTraversableResponse {
		$user = $this->requireUser();
		$input = fopen('php://input', 'rb');
		$rawBody = $input !== false ? stream_get_contents($input, 12 * 1024 * 1024 + 1) : false;
		if (is_resource($input)) {
			fclose($input);
		}
		if (is_string($rawBody) && strlen($rawBody) > 12 * 1024 * 1024) {
			return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Chat request exceeds the 12 MB limit.']) . "\n"]), 413, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
		}
		$body = is_string($rawBody) ? json_decode($rawBody, true) : null;
		$request = null;
		if ($user !== null) {
			try {
				$request = ChatCompletionRequest::fromArray(is_array($body) ? $body : []);
			} catch (\InvalidArgumentException $error) {
				return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => $error->getMessage()]) . "\n"]), 400, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
			}
		}
		if ($user !== null && ($limited = $this->rateLimitResponse($user, 'stream', $this->config->getInt('rate_limit_stream_per_minute', 10))) !== null) {
			return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Too many requests. Please retry shortly.']) . "\n"]), 429, ['Content-Type' => 'application/x-ndjson', 'Retry-After' => '60', 'Cache-Control' => 'no-cache, no-store, must-revalidate']);
		}
		// Resolve potentially failing storage reads before acquiring the slot.
		$scopePath = $this->scopePathFor($user, $request?->chatId);
		$custom = $this->customFor($user, $request?->chatId);
		try {
			$chatSlot = $user !== null ? $this->acquireChatSlot($user) : null;
		} catch (\Throwable) {
			return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Chat is temporarily unavailable. Please retry shortly.']) . "\n"]), 503, ['Content-Type' => 'application/x-ndjson']);
		}
		if ($user !== null && $chatSlot === null) {
			return new StreamTraversableResponse(new \ArrayIterator([json_encode(['type' => 'error', 'message' => 'Another chat request is already running. Please retry shortly.']) . "\n"]), 429, ['Content-Type' => 'application/x-ndjson', 'Retry-After' => '5']);
		}
		$message = $request?->message ?? '';
		$history = $request?->history ?? [];
		$images = $request?->images ?? [];
		$model = $request?->model;
		if ($user !== null && $message !== '') {
			$this->releaseSessionLock();
		}
		$generator = $this->streamEvents($user, $message, $history, $images, $model, $scopePath, $custom, $chatSlot);

		return new StreamTraversableResponse($generator, 200, [
			'Content-Type' => 'application/x-ndjson',
			'Cache-Control' => 'no-cache, no-store, must-revalidate',
			'X-Accel-Buffering' => 'no',
		]);
	}

	private function streamEvents(?string $user, string $message, array $history, array $images, ?string $model, string $scopePath, array $custom, ?string $chatSlot): \Generator {
		$gen = null;
		$terminal = null;
		try {
			if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
				while (ob_get_level() > 0) {
					@ob_end_flush();
				}
			}
			if ($user === null) {
				yield json_encode(['type' => 'error', 'message' => 'Not logged in']) . "\n";
				return;
			}
			if ($this->clientDisconnected()) {
				return;
			}
			$gen = $this->ragService->askStream(new ChatRequest(
				userId: $user,
				message: $message,
				history: $history,
				scopePath: $scopePath,
				instructions: $custom['instructions'],
				persona: $custom['persona'],
				images: $images,
				model: $model,
			));
			foreach ($gen as $line) {
				if ($this->clientDisconnected()) {
					return;
				}
				$event = json_decode((string)$line, true);
				if (is_array($event) && in_array($event['type'] ?? '', ['done', 'confirmation', 'error'], true)) {
					if (($event['type'] ?? '') === 'done') {
						$answer = (string)($event['answer'] ?? '');
						if ($answer !== '' && $this->config->get('notify_on_complete') === '1') {
							$this->sendAnswerNotification($user, $answer);
						}
					}
					$terminal = $line;
					break;
				}
				yield $line;
				if ($this->clientDisconnected()) {
					return;
				}
			}
		} finally {
			$gen = null;
			$this->releaseChatSlot($chatSlot);
		}
		if ($terminal !== null && !$this->clientDisconnected()) {
			yield $terminal;
		}
	}

	private function clientDisconnected(): bool {
		return function_exists('connection_aborted') && connection_aborted() > 0;
	}

	private function sendAnswerNotification(string $user, string $text): void {
		try {
			$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
			if (!$this->appManager->isInstalled('notifications')) {
				return;
			}
			$url = \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute('eva_ai.page.app');
			$notification = $manager->createNotification();
			$notification->setApp('eva_ai')
				->setUser($user)
				->setObject('chat', 'answer')
				->setSubject('answer_ready', ['text' => mb_strimwidth($text, 0, 400, '…')])
				->setLink($url)
				->setDateTime(new \DateTime());
			$manager->notify($notification);
		} catch (\Throwable) {
			// Notifications must never break the chat stream.
		}
	}
}
