<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use PHPUnit\Framework\TestCase;

final class ApiRouteCompatibilityTest extends TestCase {
    public function testLegacyRoutesRemainWhileRestStyleAliasesAreAvailable(): void {
        $routes = require dirname(__DIR__) . '/appinfo/routes.php';
        $all = array_merge($routes['routes'] ?? [], $routes['ocs'] ?? []);
        $signatures = array_map(static fn(array $route): string => ($route['url'] ?? '') . ' ' . ($route['verb'] ?? ''), $all);
        foreach ([
            '/api/mailIndex POST', '/api/mail_index POST',
            '/api/talkIndex POST', '/api/talk_index POST',
            '/api/indexStop POST', '/api/index/stop POST',
            '/api/indexReset POST', '/api/index/reset POST',
            '/api/folders/delete POST', '/api/folders DELETE',
            '/api/chats/{id}/reaction POST', '/api/feedback/stats GET',
            '/api/templates GET', '/api/templates POST', '/api/templates/export GET',
            '/api/templates/import POST', '/api/templates/{id} DELETE', '/api/templates/{id}/use POST',
            '/api/briefings/history GET', '/api/briefings/{id}/run POST',
        ] as $signature) {
            self::assertContains($signature, $signatures);
        }
        $routeNames = array_column($all, 'name');
        foreach (['api#startMailIndexSnake', 'api#startTalkIndexSnake', 'api#stopIndexSnake', 'api#resetIndexSnake', 'api#deleteFolderRest', 'api#chatReaction', 'api#feedbackStats', 'api#templates', 'api#saveTemplate', 'api#exportTemplates', 'api#importTemplates', 'api#deleteTemplate', 'api#useTemplate', 'api#briefingHistory', 'api#runBriefingNow'] as $routeName) {
            self::assertContains($routeName, $routeNames, 'Missing route handler ' . $routeName);
        }
    }
}
