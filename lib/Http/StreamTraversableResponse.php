<?php

declare(strict_types=1);

namespace OCA\EvaAi\Http;

use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/** Stream through the callback API available on all supported Nextcloud versions. */
final class StreamTraversableResponse extends Response implements ICallbackResponse {
    public function __construct(private \Traversable $generator, int $status = 200, array $headers = []) {
        parent::__construct($status, $headers);
    }

    public function callback(IOutput $output): void {
        foreach ($this->generator as $content) {
            $output->setOutput($content);
            flush();
        }
    }
}
