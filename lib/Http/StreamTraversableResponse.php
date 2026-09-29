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
        try {
            foreach ($this->generator as $content) {
                if (connection_aborted()) {
                    return;
                }
                $output->setOutput($content);
                flush();
            }
        } catch (\Throwable) {
            if (connection_aborted()) {
                return;
            }
            $output->setOutput(json_encode([
                'type' => 'error',
                'message' => 'The response stream ended unexpectedly. Please retry.',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            flush();
        }
    }
}
