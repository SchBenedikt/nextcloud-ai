<?php

declare(strict_types=1);

namespace OCA\EvaAi\Vector;

/** Safe reason codes only; never chain an HTTP exception containing credentials. */
final class VectorStoreException extends \RuntimeException {
    public function __construct(public readonly string $reason) { parent::__construct('Vector store operation failed: ' . $reason); }
}
