<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testFailureSubjectUsesRequestedLanguageAndKeepsDynamicMessage(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->expects(self::once())->method('t')->with('EVA task failed')->willReturn('EVA-Aufgabe fehlgeschlagen');
        $factory = $this->createMock(IFactory::class);
        $factory->expects(self::once())->method('get')->with('eva_ai', 'de')->willReturn($l10n);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->with('eva_ai', 'app.svg')->willReturn('/apps/eva_ai/app.svg');
        $urls->method('getAbsoluteURL')->with('/apps/eva_ai/app.svg')->willReturn('https://cloud.example/apps/eva_ai/app.svg');
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('eva_ai');
        $notification->method('getSubject')->willReturn('background_failed');
        $notification->method('getSubjectParameters')->willReturn(['text' => 'Der Lauf überschritt das Zeitlimit.']);
        $notification->expects(self::once())->method('setParsedSubject')->with('EVA-Aufgabe fehlgeschlagen')->willReturnSelf();
        $notification->expects(self::once())->method('setParsedMessage')->with('Der Lauf überschritt das Zeitlimit.')->willReturnSelf();
        $notification->expects(self::once())->method('setIcon')->with('https://cloud.example/apps/eva_ai/app.svg')->willReturnSelf();

        self::assertSame($notification, (new Notifier($urls, $factory))->prepare($notification, 'de'));
    }

    public function testRejectsNotificationsFromOtherApps(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('other');
        $this->expectException(UnknownNotificationException::class);
        (new Notifier($this->createMock(IURLGenerator::class), $this->createMock(IFactory::class)))
            ->prepare($notification, 'en');
    }
}
