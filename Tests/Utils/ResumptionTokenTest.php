<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Pumukit\OaiBundle\Utils\ResumptionToken;
use Pumukit\OaiBundle\Utils\ResumptionTokenException;

/**
 * @internal
 *
 * @coversNothing
 */
class ResumptionTokenTest extends TestCase
{
    public function testConstructAndGetter()
    {
        $token = new ResumptionToken();
        $this->assertSame(0, $token->getOffset());
        $this->assertNull($token->getFrom());
        $this->assertNull($token->getUntil());
        $this->assertNull($token->getMetadataPrefix());
        $this->assertNull($token->getSet());

        $offset = 10;
        $from = new \DateTime('yesterday');
        $until = new \DateTime('tomorrow');
        $metadataPrefix = 'oai_dc';
        $set = 'castillo';
        $token = new ResumptionToken($offset, $from, $until, $metadataPrefix, $set);
        $this->assertSame($offset, $token->getOffset());
        $this->assertSame($from, $token->getFrom());
        $this->assertSame($until, $token->getUntil());
        $this->assertSame($metadataPrefix, $token->getMetadataPrefix());
        $this->assertSame($set, $token->getSet());

        $this->assertTrue(strlen($token->encode()) > 0);
    }

    public function testInvalidDecode()
    {
        $this->expectException(ResumptionTokenException::class);
        $rawToken = base64_encode('}}~~{{');
        $token = ResumptionToken::decode($rawToken);
    }

    public function testDecode()
    {
        $rawToken = 'eyJvZmZzZXQiOjEwLCJtZXRhZGF0YVByZWZpeCI6Im9haV9kYyIsInNldCI6ImNhc3RpbGxvIiwiZnJvbSI6MTQ3MDYwNzIwMCwidW50aWwiOjE0NzA3ODAwMDB9';

        $offset = 10;
        $metadataPrefix = 'oai_dc';
        $set = 'castillo';
        $token = ResumptionToken::decode($rawToken);

        $this->assertSame($offset, $token->getOffset());
        $this->assertSame($metadataPrefix, $token->getMetadataPrefix());
        $this->assertSame($set, $token->getSet());
    }
}
