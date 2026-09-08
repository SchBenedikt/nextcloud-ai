<?php

declare(strict_types=1);

namespace OCA\EvaAi\Dashboard;

use OCA\EvaAi\Service\ChatStore;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * EVA AI dashboard widget — quick entry point on the Nextcloud Dashboard.
 *
 * Implements the items API (V1 + V2) so the dashboard renders real content:
 * a "New chat" action plus the user's most recent chats (deep-linked into
 * the app via ?chat=<id>). Widgets without any chats fall back to the
 * documents shortcut.
 */
class EVAWidget implements IWidget, IAPIWidget, IAPIWidgetV2 {
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private ChatStore $chatStore,
    ) {
    }

    public function getId(): string {
        return 'eva_ai';
    }

    public function getTitle(): string {
        return $this->l10n->t('EVA AI');
    }

    public function getOrder(): int {
        return 10;
    }

    public function getIconClass(): string {
        return 'icon-eva-ai';
    }

    public function getUrl(): ?string {
        // Route-Referenzen verwenden Punkte (eva_ai.page.index), NICHT das
        // '#'-Format aus routes.php. Mit '#' wirft die URL-Generierung eine
        // RouteNotFoundException und das Dashboard-Widget bleibt haengen.
        return $this->urlGenerator->linkToRouteAbsolute('eva_ai.page.index');
    }

    public function load(): void {
        \OCP\Util::addStyle('eva_ai', 'widget');
    }

    /** @return list<WidgetItem> */
    private function items(string $userId, ?string $since, int $limit): array {
        $appUrl = $this->urlGenerator->linkToRouteAbsolute('eva_ai.page.index');
        $docsUrl = $this->urlGenerator->linkToRouteAbsolute('eva_ai.page.documents');
        $icon = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('eva_ai', 'app.svg'));

        $items = [
            // Always-on action: opens the app and starts a fresh conversation.
            new WidgetItem(
                $this->l10n->t('New chat'),
                $this->l10n->t('Start a new conversation'),
                $appUrl . '?chat=new',
                $icon,
                'eva-new-chat',
            ),
        ];

        // The user's most recent chats, newest first. The since-id is the
        // chat id itself so new conversations surface as new widget items
        // without confusing the dashboard's delta tracking.
        $chatLimit = $limit > 0 ? max(0, $limit - count($items)) : 0;
        if ($chatLimit > 0) {
            try {
                $chats = $this->chatStore->list($userId);
                // Empty conversations (accidental "New chat" clicks) are not
                // interesting as recent chats.
                $chats = array_values(array_filter($chats, static fn($c) => (int)($c['count'] ?? 0) > 0));
                $chats = array_slice($chats, 0, $chatLimit);
                foreach ($chats as $chat) {
                    $items[] = new WidgetItem(
                        (string)($chat['title'] ?? $this->l10n->t('New chat')),
                        $this->l10n->t('%s messages', [(string)(int)($chat['count'] ?? 0)]),
                        $appUrl . '?chat=' . rawurlencode((string)($chat['id'] ?? '')),
                        $icon,
                        'eva-chat-' . (string)($chat['id'] ?? ''),
                    );
                }
            } catch (\Throwable $e) {
                // A broken chat store must never break the dashboard.
            }
        }

        if (count($items) === 1 && $limit !== 1) {
            // No chats (or chat store unavailable): offer the documents view
            // so the widget is still useful instead of showing an empty list.
            $items[] = new WidgetItem(
                $this->l10n->t('Your documents'),
                $this->l10n->t('View the indexed documents of your knowledge base.'),
                $docsUrl,
                $icon,
                'eva-docs',
            );
        }

        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }
        return $items;
    }

    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        return $this->items($userId, $since, $limit);
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        return new WidgetItems(
            $this->items($userId, $since, $limit),
            $this->l10n->t('No chats yet — start a new one.'),
            $this->l10n->t('No chats yet — start a new one.'),
        );
    }
}