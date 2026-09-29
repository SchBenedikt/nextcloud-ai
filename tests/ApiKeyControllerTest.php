<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\ApiKeyController;
use OCA\EvaAi\Db\ChunkMapper;
use OCA\EvaAi\Db\DocumentMapper;
use OCA\EvaAi\Service\ApiKeyService;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\RagService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class ApiKeyControllerTest extends TestCase {
	protected function setUp(): void {
		if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
			self::markTestSkipped('Nextcloud OCP interfaces are not available.');
		}
	}

	private function controller(IRequest $request, ApiKeyService $keys, ?string $userId = null): ApiKeyController {
		return new ApiKeyController(
			'eva_ai', $request, $userId, $keys, $this->createMock(AppConfig::class),
			$this->createMock(RagService::class), $this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class), $this->createMock(ChatStore::class),
			$this->createMock(DocumentMapper::class), $this->createMock(ChunkMapper::class),
		);
	}

	public function testExternalChatRejectsRequestWithoutBearerKey(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$keys = $this->createMock(ApiKeyService::class);
		$keys->expects(self::never())->method('authenticate');
		$response = $this->controller($request, $keys)->externalChat();
		self::assertSame(401, $response->getStatus());
		self::assertSame('invalid_api_key', $response->getData()['error']);
	}

	public function testReadKeyCannotSendChat(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer eva_sk_' . str_repeat('A', 43));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$keys = $this->createMock(ApiKeyService::class);
		$keys->expects(self::once())->method('authenticate')->willReturn(['user_id' => 'alice', 'scope' => 'read']);
		$response = $this->controller($request, $keys)->externalChat();
		self::assertSame(403, $response->getStatus());
		self::assertSame('insufficient_scope', $response->getData()['error']);
	}

	public function testWriteKeyChatRemainsBoundToOwnerAndCannotRunActions(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer eva_sk_' . str_repeat('A', 43));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$request->method('getParam')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $key === 'message' ? 'Hello' : $default);
		$keys = $this->createMock(ApiKeyService::class);
		$keys->method('authenticate')->willReturn(['user_id' => 'alice', 'scope' => 'write']);
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->expects(self::once())->method('get')->with('alice')->willReturn($user);
		$rag = $this->createMock(RagService::class);
		$rag->expects(self::once())->method('ask')->with(self::callback(static fn($request): bool => $request instanceof \OCA\EvaAi\Dto\ChatRequest && $request->userId === 'alice' && !$request->allowActions))->willReturn(['answer' => 'Hello']);
		$controller = new ApiKeyController(
			'eva_ai', $request, null, $keys, $this->createMock(AppConfig::class), $rag,
			$userManager, $this->createMock(IGroupManager::class), $this->createMock(ChatStore::class),
			$this->createMock(DocumentMapper::class), $this->createMock(ChunkMapper::class),
		);
		$response = $controller->externalChat();
		self::assertSame(200, $response->getStatus());
	}

	public function testKeyEndpointsAndExternalRoutesAreRegistered(): void {
		$routes = (string)file_get_contents(__DIR__ . '/../appinfo/routes.php');
		foreach (['api_key#listKeys', 'api_key#createKey', 'api_key#revokeKey', 'api_key#externalChat', 'api_key#externalChats', 'api_key#externalChatDetail', 'api_key#externalDocuments', 'api_key#externalDocumentChunks'] as $route) {
			self::assertStringContainsString($route, $routes);
		}
	}
}
