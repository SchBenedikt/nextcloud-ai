<?php
declare(strict_types=1);

namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Http\StreamTraversableResponse;
use OCP\AppFramework\Http\IOutput;
use PHPUnit\Framework\TestCase;

final class StreamTraversableResponseTest extends TestCase {
    protected function setUp(): void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) {
            self::markTestSkipped('Nextcloud OCP interfaces are not available');
        }
    }

    public function testMidStreamExceptionProducesSafeErrorEvent(): void {
        $generator = (static function (): \Generator {
            yield "{\"type\":\"delta\",\"text\":\"partial\"}\n";
            throw new \RuntimeException('sensitive upstream detail');
        })();
        $output = $this->createMock(IOutput::class);
        $written = [];
        $output->expects(self::exactly(2))->method('setOutput')->willReturnCallback(
            static function (string $value) use (&$written): void { $written[] = $value; }
        );

        (new StreamTraversableResponse($generator))->callback($output);

        self::assertSame("{\"type\":\"delta\",\"text\":\"partial\"}\n", $written[0]);
        self::assertSame([
            'type' => 'error',
            'message' => 'The response stream ended unexpectedly. Please retry.',
        ], json_decode($written[1], true));
        self::assertStringNotContainsString('sensitive upstream detail', $written[1]);
    }
}
