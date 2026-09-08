<?php

declare(strict_types=1);

namespace OCA\EvaAi\Listener;

use OCA\EvaAi\Service\DirtyIndexStore;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Incremental re-indexing via Nextcloud file hooks (Issue #79).
 *
 * Changes are only queued (per user, debounced through one ReindexFileJob),
 * never processed synchronously: extraction + embedding stay in the background
 * worker where they cannot block the user's request, and sync-client bursts
 * collapse into a single batched drain. Events outside the user's scope are
 * dropped by the Indexer itself when the queued file is resolved.
 */
class FileChangeListener implements IEventListener {
    /** Debounce window: events within this window share one job run. */
    private const DEBOUNCE_SECONDS = 45;

    public function __construct(
        private DirtyIndexStore $dirty,
        private IJobList $jobList,
        private LoggerInterface $logger
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof NodeRenamedEvent) {
            $this->handleRename($event);
            return;
        }
        if (!$event instanceof NodeCreatedEvent && !$event instanceof NodeWrittenEvent && !$event instanceof NodeDeletedEvent) {
            return;
        }
        $node = $event->getNode();
        if ($node instanceof Folder && $event instanceof NodeDeletedEvent) {
            // A whole folder vanished: purge everything that was indexed under it.
            $this->queue($node, 'folder-delete');
            return;
        }
        if ($node instanceof File) {
            $this->queue($node, 'file');
        }
        // Folder creations need no index work.
    }

    private function handleRename(NodeRenamedEvent $event): void {
        $source = $event->getSource();
        $target = $event->getTarget();
        if ($source instanceof File) {
            // Same file id, new path: reindexing refreshes path + content.
            $this->queue($source, 'file');
            return;
        }
        if ($source instanceof Folder) {
            $this->queue($source, 'folder-rename', $target);
        }
    }

    private function queue(Node $node, string $kind, ?Node $target = null): void {
        $userId = $this->userIdFor($node);
        if ($userId === null || $userId === '') {
            return;
        }
        try {
            if ($kind === 'file') {
                $this->dirty->markFile($userId, (int)$node->getId());
            } elseif ($kind === 'folder-delete') {
                $this->dirty->markFolderDeleted($userId, $this->relativePath($userId, $node->getPath()));
            } elseif ($kind === 'folder-rename') {
                $old = $this->relativePath($userId, $node->getPath());
                $new = $target !== null ? $this->relativePath($userId, $target->getPath()) : $old;
                if ($old === '' || $old === $new) {
                    return;
                }
                $this->dirty->markFolderRenamed($userId, $old, $new);
            }
            // One debounced job per user drains the whole queue (IJobList
            // updates the run time when the argument already exists).
            $this->jobList->scheduleAfter(\OCA\EvaAi\BackgroundJob\ReindexFileJob::class, self::DEBOUNCE_SECONDS, ['userId' => $userId]);
        } catch (\Throwable $e) {
            // Never break the user's filesystem operation over indexing.
            $this->logger->debug('eva_ai file change queue failed', [
                'userId' => $userId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function relativePath(string $userId, string $path): string {
        $prefix = '/' . $userId . '/files';
        if (str_starts_with($path, $prefix)) {
            return ltrim(substr($path, strlen($prefix)), '/');
        }
        return ltrim($path, '/');
    }

    private function userIdFor(Node $node): ?string {
        try {
            $owner = $node->getOwner();
            if ($owner !== null) {
                $uid = $owner->getUid();
                if (is_string($uid) && $uid !== '') {
                    return $uid;
                }
            }
        } catch (\Throwable $e) {
            // Deleted nodes may lose their owner; fall back to the path.
        }
        if (preg_match('#^/([^/]+)/#', (string)$node->getPath(), $m)) {
            return $m[1];
        }
        return null;
    }
}