<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\EmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Issue97EmailServiceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testDatabaseFailureIsLoggedAndPropagated(): void {
        $exception = new \RuntimeException('mail schema unavailable');
        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->expects(self::once())
            ->method('prepare')
            ->willThrowException($exception);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'eva_ai: Mail database query failed',
                self::callback(static function (array $context): bool {
                    return isset($context['exception'], $context['sql'])
                        && $context['exception'] instanceof \Throwable
                        && str_contains((string)$context['sql'], 'mail_accounts');
                })
            );

        $service = new EmailService($db, $logger);
        $this->expectExceptionObject($exception);
        $service->accountsOf('alice');
    }

    public function testEmptyMailboxIsStillAValidEmptyResult(): void {
        $statement = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $statement->method('execute')->willReturn($this->createMock(\OCP\DB\IResult::class));
        $statement->method('fetchAll')->willReturn([]);
        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->method('prepare')->willReturn($statement);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $service = new EmailService($db, $logger);
        self::assertSame([], $service->accountsOf('alice'));
    }
}
