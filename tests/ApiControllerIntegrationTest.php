<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\BackgroundJob\IndexRequestJob;
use OCA\EvaAi\Controller\ApiController;
use OCA\EvaAi\Controller\SystemController;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Dto\ChatRequest;
use OCA\EvaAi\Dto\FeedbackStatsResponse;
use OCA\EvaAi\Service\ActionExecutor;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BackgroundChatQueue;
use OCA\EvaAi\Service\ChatLearner;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\FileContextChatService;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\Indexer;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCA\EvaAi\Service\LockGuard;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\UsageMetrics;
use OCA\EvaAi\Service\UserDataService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Exercises API controller request validation, responses and service wiring. */
final class ApiControllerIntegrationTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
			self::markTestSkipped('Nextcloud OCP interfaces are not available.');
		}
	}

	/**
	 * @param array<string,mixed> $params
	 * @param array<string,mixed> $dependencies
	 * @param array<string,mixed> $storedValues
	 */
	private function controller(?string $userId, array $params, array &$dependencies, array &$storedValues): ApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn(string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);

		$ncConfig = $this->createMock(IConfig::class);
		$ncConfig->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use (&$storedValues): string {
				return $storedValues['app'][$key] ?? $default;
			}
		);
		$ncConfig->method('getUserValue')->willReturnCallback(
			static function (string $user, string $app, string $key, mixed $default = '') use (&$storedValues): string {
				return $storedValues['users'][$user][$key] ?? $default;
			}
		);
		$ncConfig->method('setUserValue')->willReturnCallback(
			static function (string $user, string $app, string $key, string $value, ?string $precondition = null) use (&$storedValues): void {
				$storedValues['users'][$user][$key] = $value;
			}
		);
		$ncConfig->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$storedValues): void {
				$storedValues['app'][$key] = $value;
			}
		);

		$config = new AppConfig($ncConfig);
		$rag = $this->createMock(RagService::class);
		$executor = $this->createMock(ActionExecutor::class);
		$indexer = $this->createMock(Indexer::class);
		$ollama = $this->createMock(Ollama::class);
		$documentMapper = $this->createMock(DocumentMapper::class);
		$chunkMapper = $this->createMock(ChunkMapper::class);
		$jobList = $this->createMock(IJobList::class);
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$chatStore = $this->createMock(ChatStore::class);
		$fileContextChat = $this->createMock(FileContextChatService::class);
		$appManager = $this->createMock(IAppManager::class);
		$knowledgeInitializer = $this->createMock(KnowledgeInitializer::class);
		$lockGuard = $this->createMock(LockGuard::class);
		$userDataService = $this->createMock(UserDataService::class);
		$chatLearner = $this->createMock(ChatLearner::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$indexScheduler = $this->createMock(IndexScheduler::class);
		$usageMetrics = $this->createMock(UsageMetrics::class);
		$logger = $this->createMock(LoggerInterface::class);
		$backgroundChatQueue = new BackgroundChatQueue($ncConfig, $lockingProvider, $logger);
		$systemController = new SystemController(
			'eva_ai', $request, $userId, $config, $rag, $documentMapper, $chatStore,
			$knowledgeInitializer, $indexScheduler, $usageMetrics, $ollama,
			$cacheFactory, $backgroundChatQueue, $logger,
		);

		$dependencies = compact('config', 'rag', 'executor', 'indexer', 'jobList', 'lockingProvider', 'chatStore', 'knowledgeInitializer', 'lockGuard', 'cacheFactory', 'logger');
		return new ApiController(
			'eva_ai', $request, $userId, $config, $rag, $executor, $indexer, $ollama,
			$documentMapper, $chunkMapper, $jobList, $lockingProvider, $chatStore,
			$fileContextChat, $appManager, $knowledgeInitializer, $lockGuard,
			$userDataService, $chatLearner, $cacheFactory, $indexScheduler, $usageMetrics,
			$backgroundChatQueue, $logger, $systemController,
		);
	}

	public function testAnonymousChatAndToolConfirmationReturn401Responses(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller(null, [], $dependencies, $stored);

		$chat = $controller->chat();
		$confirmation = $controller->confirmTool();

		self::assertSame(401, $chat->getStatus());
		self::assertSame(['code' => 'unauthorized', 'message' => 'Not logged in'], $chat->getData()['error']);
		self::assertSame(401, $confirmation->getStatus());
		self::assertSame(['code' => 'unauthorized', 'message' => 'Not logged in'], $confirmation->getData()['error']);
	}

	public function testInvalidChatMessagesDoNotConsumeRateLimitOrChatLocks(): void {
		foreach (['', str_repeat('x', 50001)] as $message) {
			$stored = [];
			$dependencies = [];
			$controller = $this->controller('alice', ['message' => $message], $dependencies, $stored);
			$dependencies['cacheFactory']->expects(self::never())->method('createLocking');
			$dependencies['lockingProvider']->expects(self::never())->method('acquireLock');
			$dependencies['rag']->expects(self::never())->method('ask');

			$response = $controller->chat();

			self::assertSame(400, $response->getStatus());
			self::assertSame('invalid_request', $response->getData()['error']['code']);
		}
	}

	public function testBackgroundChatLifecycleEndpointsRejectInvalidQueueIds(): void {
		foreach (['cancelBackgroundChat', 'pauseBackgroundChat', 'resumeBackgroundChat', 'retryBackgroundChat'] as $method) {
			$stored = [];
			$dependencies = [];
			$controller = $this->controller('alice', ['id' => []], $dependencies, $stored);

			$response = $controller->{$method}();

			self::assertSame(400, $response->getStatus(), $method . ' should reject a non-string queue id');
		}
	}

	public function testFeedbackStatsReturnsItsTypedResponseShape(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller('alice', [], $dependencies, $stored);
		$dependencies['chatStore']->expects(self::once())->method('feedbackStats')->with('alice')->willReturn([
			'helpful' => 4, 'notHelpful' => 2, 'bookmarked' => 3,
		]);

		$response = $controller->feedbackStats();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['helpful' => 4, 'notHelpful' => 2, 'bookmarked' => 3], $response->getData());
	}

	public function testChatReactionValidatesInputAndReturnsTypedResult(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller('alice', ['index' => '2', 'type' => 'helpful', 'value' => 'true'], $dependencies, $stored);
		$dependencies['chatStore']->expects(self::once())->method('setMessageReaction')->with('alice', 'chat-1', 2, 'helpful', true)
			->willReturn(['reactions' => ['helpful' => true, 'updated' => 123], 'rev' => 9]);

		$response = $controller->chatReaction('chat-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['ok' => true, 'reactions' => ['helpful' => true, 'updated' => 123], 'rev' => 9], $response->getData());
	}

	public function testInvalidChatReactionDoesNotCallTheStore(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller('alice', ['index' => [], 'type' => 'unknown', 'value' => 'maybe'], $dependencies, $stored);
		$dependencies['chatStore']->expects(self::never())->method('setMessageReaction');

		$response = $controller->chatReaction('chat-1');

		self::assertSame(400, $response->getStatus());
	}

	public function testValidChatRequestCallsRagAndReturnsItsResponse(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller('alice', ['message' => 'Summarize my notes'], $dependencies, $stored);
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturn(1);
		$dependencies['cacheFactory']->method('createLocking')->with('eva_ai_rate')->willReturn($cache);
		$dependencies['lockingProvider']->expects(self::once())->method('acquireLock')->with(
			'eva_ai/chat/' . hash('sha256', 'alice'), ILockingProvider::LOCK_EXCLUSIVE, 'EVA chat request'
		);
		$dependencies['lockingProvider']->expects(self::once())->method('releaseLock')->with(
			'eva_ai/chat/' . hash('sha256', 'alice'), ILockingProvider::LOCK_EXCLUSIVE
		);
		$dependencies['rag']->expects(self::once())->method('ask')
			->with(self::callback(static fn(ChatRequest $request): bool => $request->userId === 'alice' && $request->message === 'Summarize my notes'))
			->willReturn(['answer' => 'Your notes contain three tasks.', 'sources' => []]);

		$response = $controller->chat();

		self::assertSame(200, $response->getStatus());
		self::assertSame('Your notes contain three tasks.', $response->getData()['answer']);
	}

	public function testSettingsSaveIsVisibleOnTheNextRead(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller('alice', ['temperature' => '0.7'], $dependencies, $stored);

		$saved = $controller->saveSettings();
		$reloaded = $controller->settings();

		self::assertSame(200, $saved->getStatus());
		self::assertSame(200, $reloaded->getStatus());
		self::assertSame('0.7', $dependencies['config']->get('temperature'));
		self::assertSame('0.7', $reloaded->getData()['temperature']);
	}

	public function testIndexRequestQueuesTheExpectedBackgroundJob(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller('alice', [], $dependencies, $stored);
		$jobArguments = null;
		$dependencies['lockGuard']->expects(self::once())->method('acquireIndexLock')->with('alice', LockGuard::indexLockPath('alice'));
		$dependencies['lockingProvider']->expects(self::once())->method('releaseLock')->with(LockGuard::indexLockPath('alice'), ILockingProvider::LOCK_EXCLUSIVE);
		$dependencies['jobList']->expects(self::once())->method('add')->willReturnCallback(
			static function (string $class, array $arguments) use (&$jobArguments): void {
				self::assertSame(IndexRequestJob::class, $class);
				self::assertSame('alice', $arguments['userId'] ?? null);
				self::assertSame('all', $arguments['mode'] ?? null);
				self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string)($arguments['runId'] ?? ''));
				$jobArguments = $arguments;
			}
		);
		$dependencies['rag']->method('buildStatus')->willReturn(['indexing' => true]);

		$response = $controller->startIndex();

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['queued']);
		self::assertSame('all', $response->getData()['mode']);
		self::assertSame('1', $dependencies['config']->get('index_running'));
		self::assertSame('all', $dependencies['config']->get('index_mode'));
		self::assertIsArray($jobArguments);

		$dependencies['indexer']->expects(self::once())->method('run')->with('alice', null, 'all', true, $jobArguments['runId'])->willReturn([
			'processed' => 0,
			'error' => null,
		]);
		$job = new class (
			$this->createMock(ITimeFactory::class),
			$dependencies['config'],
			$dependencies['indexer'],
			$dependencies['logger'],
			$dependencies['jobList'],
		) extends IndexRequestJob {
			public function runJobDirectly(array $arguments): void {
				$this->run($arguments);
			}
		};
		$job->runJobDirectly($jobArguments);

		$dependencies['config']->setUserId('alice');
		self::assertSame('0', $dependencies['config']->get('index_running'));
		self::assertSame('idle', $dependencies['config']->get('index_mode'));
		self::assertNotSame('0', $dependencies['config']->get('index_finished'));
	}

	public function testDuplicateToolConfirmationReturnsConflictWithoutExecutingAgain(): void {
		$stored = [];
		$dependencies = [];
		$controller = $this->controller('alice', [
			'name' => 'create_file',
			'arguments' => ['path' => 'Notes/example.txt', 'content' => 'new'],
			'chatId' => 'chat-1',
			'confirmationToken' => 'token-1',
		], $dependencies, $stored);
		$dependencies['chatStore']->expects(self::once())->method('claimConfirmation')->with('alice', 'chat-1', 'token-1')->willReturn('already');
		$dependencies['executor']->expects(self::never())->method('runConfirmed');

		$response = $controller->confirmTool();

		self::assertSame(409, $response->getStatus());
		self::assertTrue($response->getData()['alreadyProcessed']);
		self::assertSame('conflict', $response->getData()['error']['code']);
	}
}
