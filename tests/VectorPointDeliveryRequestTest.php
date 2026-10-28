<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;
use OCA\EvaAi\Dto\VectorPointDeliveryRequest;
use PHPUnit\Framework\TestCase;

final class VectorPointDeliveryRequestTest extends TestCase {
    public function testConsentRequiresARealJsonBoolean():void {
        foreach ([true,false] as $value) self::assertSame($value,VectorPointDeliveryRequest::fromArray(['enabled'=>$value])->enabled);
        foreach (['true','false',1,0,null,[],new \stdClass()] as $value) {
            try { VectorPointDeliveryRequest::fromArray(['enabled'=>$value]);self::fail('Invalid consent was accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
