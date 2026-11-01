<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Dto\ChatTurnRequest;
use PHPUnit\Framework\TestCase;

final class ChatTurnRequestTest extends TestCase {
    public function testValidatesBothMessagesBeforePersistence(): void {
        $valid = ['requestId'=>'turn_1234567890123456','expectedRev'=>0,'question'=>' Question ','answer'=>['text'=>' Answer ','followups'=>['Next?']]];
        $turn = ChatTurnRequest::fromArray($valid);
        self::assertSame('Question',$turn->question->text);
        self::assertSame('assistant',$turn->answer->role);
        self::assertSame('Answer',$turn->answer->text);
        self::assertSame($turn->fingerprint(), ChatTurnRequest::fromArray($valid)->fingerprint());
        foreach ([['requestId'=>'bad'],['expectedRev'=>'0'],['expectedRev'=>-1],['question'=>''],['answer'=>['text'=>'']],['answer'=>['text'=>'Answer','regenerateRev'=>1]]] as $change) {
            try { ChatTurnRequest::fromArray(array_replace($valid,$change)); self::fail('Invalid turn accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
