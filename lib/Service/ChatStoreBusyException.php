<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/**
 * Raised when the per-user chat store cannot be accessed because another
 * request (or a crashed request whose lock has not expired yet) currently
 * holds the write lock. Controllers convert this into a friendly 503 "busy"
 * response instead of an opaque 500, so a temporarily locked chat store can
 * never take the whole app page down (page loads read chats, stats and
 * folders in parallel).
 */
class ChatStoreBusyException extends \RuntimeException {
}