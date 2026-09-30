<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\SystemController;
use OCA\EvaAi\Service\AppConfig;
use OCA\EvaAi\Service\BackgroundChatQueue;
use OCA\EvaAi\Service\ChatStore;
use OCA\EvaAi\Service\IndexScheduler;
use OCA\EvaAi\Service\KnowledgeInitializer;
use OCA\EvaAi\Service\Ollama;
use OCA\EvaAi\Service\RagService;
use OCA\EvaAi\Service\UsageMetrics;
use OCA\EvaAi\Db\DocumentMapper;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Controller-level checks that run when the Nextcloud OCP API is available. */
final class SystemControllerIntegrationTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            self::markTestSkipped('Nextcloud OCP interfaces are not available in this environment.');
        }
    }

    private function controller(IRequest $request, ?string $userId, UsageMetrics $usageMetrics): SystemController {
        return new SystemController(
            'eva_ai', $request, $userId, $this->createMock(AppConfig::class),
            $this->createMock(RagService::class), $this->createMock(DocumentMapper::class),
            $this->createMock(ChatStore::class), $this->createMock(KnowledgeInitializer::class),
            $this->createMock(IndexScheduler::class), $usageMetrics, $this->createMock(Ollama::class),
            $this->createMock(ICacheFactory::class),
            new BackgroundChatQueue(
                $this->createMock(IConfig::class),
                $this->createMock(ILockingProvider::class),
                $this->createMock(LoggerInterface::class),
            ),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testUnauthenticatedSystemEndpointsReturn401(): void {
        $controller = $this->controller($this->createMock(IRequest::class), null, $this->createMock(UsageMetrics::class));

        foreach (['status', 'stats', 'metrics', 'health', 'greeting'] as $method) {
            $response = $controller->{$method}();
            self::assertSame(401, $response->getStatus(), $method . ' must reject anonymous requests');
        }
    }

    public function testMetricsUsesTheRequestedDateRange(): void {
        $request = $this->createMock(IRequest::class);
        $request->expects(self::once())->method('getParam')->with('days', 30)->willReturn('7');
        $usageMetrics = $this->createMock(UsageMetrics::class);
        $usageMetrics->expects(self::once())->method('summaryForUser')->with('alice', 7)->willReturn([
            'days' => 7, 'totals' => [], 'by_model' => [], 'daily' => [], 'slow_tools' => [],
        ]);

        $response = $this->controller($request, 'alice', $usageMetrics)->metrics();

        self::assertSame(200, $response->getStatus());
        self::assertSame(7, $response->getData()['days']);
    }

    public function testMetricsRejectsInvalidDateRanges(): void {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('days', 30)->willReturn('0');
        $usageMetrics = $this->createMock(UsageMetrics::class);
        $usageMetrics->expects(self::never())->method('summaryForUser');

        $response = $this->controller($request, 'alice', $usageMetrics)->metrics();

        self::assertSame(400, $response->getStatus());
    }

    public function testSystemRoutesKeepTheirPublicUrls(): void {
        $routes = (string)file_get_contents(__DIR__ . '/../appinfo/routes.php');
        foreach (['status', 'stats', 'metrics', 'health', 'greeting'] as $endpoint) {
            self::assertStringContainsString("system#{$endpoint}", $routes);
            self::assertStringContainsString("'/api/{$endpoint}'", $routes);
        }
    }
}
