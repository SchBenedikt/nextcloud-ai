<?php

declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dashboard\EVAWidget;
use OCA\EvaAi\Service\ChatStore;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard widget coverage: the tile lists a "New chat" action plus the
 * user's recent chats as deep links, falls back to documents when there are
 * no chats, and never shows empty conversations.
 */
final class EVAWidgetTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            $this->markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    private function widget(array $chats): EVAWidget {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static function (string $text, array $params = []): string {
            return $params === [] ? $text : sprintf($text, ...array_values($params));
        });
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('http://localhost/nextcloud/apps/eva_ai/');
        $url->method('imagePath')->willReturn('apps/eva_ai/img/app.svg');
        $url->method('getAbsoluteURL')->willReturnCallback(static fn(string $p): string => 'http://localhost' . $p);
        $store = $this->createMock(ChatStore::class);
        $store->method('list')->willReturn($chats);
        return new EVAWidget($l10n, $url, $store);
    }

    /** @return list<WidgetItem> */
    private function itemsOf(EVAWidget $widget, int $limit = 7): array {
        $items = $widget->getItemsV2('alice', null, $limit);
        self::assertInstanceOf(WidgetItems::class, $items);
        return $items->getItems();
    }

    private function chat(string $id, string $title, int $count, int $updated = 100): array {
        return ['id' => $id, 'title' => $title, 'created' => 1, 'updated' => $updated, 'count' => $count];
    }

    public function testFirstItemIsNewChatAction(): void {
        $items = $this->itemsOf($this->widget([]), 7);
        self::assertCount(2, $items); // action + documents fallback
        self::assertSame('New chat', $items[0]->getTitle());
        self::assertSame('http://localhost/nextcloud/apps/eva_ai/?chat=new', $items[0]->getLink());
        self::assertSame('eva-new-chat', $items[0]->getSinceId());
    }

    public function testRecentChatsAreDeepLinkedAndCounted(): void {
        $chats = [
            $this->chat('c1', 'Frage eins', 3, 300),
            $this->chat('c2', 'Frage zwei', 7, 200),
        ];
        $items = $this->itemsOf($this->widget($chats), 7);

        self::assertCount(3, $items);
        self::assertSame('Frage eins', $items[1]->getTitle());
        self::assertSame('3 messages', $items[1]->getSubtitle());
        self::assertSame('http://localhost/nextcloud/apps/eva_ai/?chat=c1', $items[1]->getLink());
        self::assertSame('eva-chat-c1', $items[1]->getSinceId());
        self::assertSame('Frage zwei', $items[2]->getTitle());
        self::assertSame('7 messages', $items[2]->getSubtitle());
        self::assertSame('http://localhost/nextcloud/apps/eva_ai/?chat=c2', $items[2]->getLink());
    }

    public function testEmptyChatsAreNotListed(): void {
        $chats = [
            $this->chat('c1', 'Leer', 0),
            $this->chat('c2', 'Inhalt', 2),
        ];
        $items = $this->itemsOf($this->widget($chats), 7);

        self::assertCount(2, $items); // action + one chat (no docs fallback needed)
        self::assertSame('Inhalt', $items[1]->getTitle());
        self::assertSame('2 messages', $items[1]->getSubtitle());
    }

    public function testLimitCapsTheTotalItemCount(): void {
        $chats = [
            $this->chat('c1', 'A', 1, 100),
            $this->chat('c2', 'B', 1, 90),
            $this->chat('c3', 'C', 1, 80),
            $this->chat('c4', 'D', 1, 70),
        ];
        $items = $this->itemsOf($this->widget($chats), 3);
        self::assertCount(3, $items); // action + 2 chats
        self::assertSame('A', $items[1]->getTitle());
        self::assertSame('B', $items[2]->getTitle());
    }

    public function testWidgetUrlPointsToTheAppRoot(): void {
        $widget = $this->widget([]);
        self::assertSame('http://localhost/nextcloud/apps/eva_ai/', $widget->getUrl());
        self::assertSame('eva_ai', $widget->getId());
    }

    public function testEmptyStateMessageOnlyWhenThereAreNoChats(): void {
        // With chats, the dashboard must not show "no chats yet" above them.
        $withChats = $this->widget([$this->chat('c1', 'Frage', 3)]);
        $itemsWithChats = $withChats->getItemsV2('alice', null, 7);
        self::assertSame('', $itemsWithChats->getEmptyContentMessage());
        self::assertSame('', $itemsWithChats->getHalfEmptyContentMessage());

        // Without chats, the hint is shown and the documents fallback appears.
        $noChats = $this->widget([]);
        $itemsNoChats = $noChats->getItemsV2('alice', null, 7);
        self::assertSame('No chats yet — start a new one.', $itemsNoChats->getEmptyContentMessage());
        self::assertSame('No chats yet — start a new one.', $itemsNoChats->getHalfEmptyContentMessage());
        self::assertSame('Your documents', $itemsNoChats->getItems()[1]->getTitle());
    }
}