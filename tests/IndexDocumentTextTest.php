<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Service\{IndexDocumentText, IndexTextExtractor, EmailService, AppConfig};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class IndexDocumentTextTest extends TestCase {
    private function message(): array {return ['id'=>7,'from'=>'public','to'=>[],'sent'=>0,'subject'=>'Public'];}

    public function testFullMailBodyAvoidsUnusedAttachmentFetchAndKeepsCanonicalPrefix(): void {
        $mail=$this->createMock(EmailService::class);$body=str_repeat('Public   body. ',4000);
        $mail->method('bodyText')->willReturn($body);$mail->expects(self::never())->method('fetchAttachments');
        $extractor=new IndexTextExtractor($this->createMock(AppConfig::class),$this->createMock(LoggerInterface::class));
        $expected=$extractor->normalize("EMAIL\nFrom: public\nTo: \nDate: ".date('Y-m-d H:i',0)."\nSubject: Public\n\n".trim($body));
        self::assertSame(mb_substr($expected,0,30000),(new IndexDocumentText($extractor,$mail))->mail('alice',$this->message()));
    }

    public function testCancellationBetweenMailReadsIsNotSwallowedAsAnAttachmentFailure(): void {
        $mail=$this->createMock(EmailService::class);$mail->method('bodyText')->willReturn('Public body');$mail->expects(self::never())->method('fetchAttachments');
        $extractor=$this->createMock(IndexTextExtractor::class);$calls=0;
        $this->expectExceptionMessage('Cancelled fixture');
        (new IndexDocumentText($extractor,$mail))->mail('alice',$this->message(),static function()use(&$calls):void{if(++$calls===2)throw new \RuntimeException('Cancelled fixture');});
    }
}
