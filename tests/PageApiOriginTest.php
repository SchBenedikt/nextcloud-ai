<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Controller\PageController;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class PageApiOriginTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud interfaces required.');
    }

    public function testBrowserEndpointsPreserveTheOriginAndInstallationSubdirectory(): void {
        foreach (['' => '/ocs/v2.php/apps/eva_ai/api/', '/nextcloud' => '/nextcloud/ocs/v2.php/apps/eva_ai/api/'] as $webroot => $expected) {
            $urls = $this->createMock(IURLGenerator::class);
            $urls->method('getWebroot')->willReturn($webroot);
            $urls->expects(self::never())->method('getAbsoluteURL');
            $controller = (new \ReflectionClass(PageController::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(PageController::class, 'urlGenerator'))->setValue($controller, $urls);
            $path = new \ReflectionMethod(PageController::class, 'apiPath');
            self::assertSame($expected, $path->invoke($controller));
            self::assertSame($expected . 'streamChat?format=json', $path->invoke($controller, 'streamChat?format=json'));
        }
    }
}
