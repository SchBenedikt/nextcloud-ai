<?php

declare(strict_types=1);

namespace OCA\EvaAi\Event;

use OCA\EvaAi\Service\ToolPluginRegistry;
use OCP\EventDispatcher\Event;

/** Dispatched lazily when EVA first builds or executes its tool catalog. */
class ToolPluginRegisterEvent extends Event {
    public function __construct(public readonly ToolPluginRegistry $registry) {
        parent::__construct();
    }
}
