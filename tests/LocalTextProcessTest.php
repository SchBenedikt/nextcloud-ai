<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\LocalTextProcess;
use PHPUnit\Framework\TestCase;

final class LocalTextProcessTest extends TestCase {
    public function testArgumentsArePassedLiterallyAndOutputIsDrained(): void {
        $literal='a;$(printf unsafe)';
        self::assertSame($literal,(new LocalTextProcess())->run([PHP_BINARY,'-r','echo $argv[1];',$literal],1));
    }
    public function testExcessOutputFailsInsteadOfSilentlyTruncating(): void {
        $this->expectException(\RuntimeException::class);
        (new LocalTextProcess())->run([PHP_BINARY,'-r','echo str_repeat("x",1000);'],1,64);
    }
    public function testFailedConverterDoesNotExposeItsStderr(): void {
        try{(new LocalTextProcess())->run([PHP_BINARY,'-r','fwrite(STDERR,"private source");exit(4);'],1);self::fail('Failure accepted.');}
        catch(\RuntimeException $error){self::assertStringNotContainsString('private source',$error->getMessage());}
    }
    public function testDeadlineStopsAStalledTool(): void {
        $start=microtime(true);
        try{(new LocalTextProcess())->run([PHP_BINARY,'-r','usleep(5000000);'],0.05);self::fail('Stalled tool completed.');}
        catch(\RuntimeException){self::assertLessThan(3,microtime(true)-$start);}
    }
}
