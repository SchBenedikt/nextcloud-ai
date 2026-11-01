<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

/** Signals that background extraction should stop so an interactive chat can run. */
final class ChatPriorityYieldException extends \RuntimeException {
}
