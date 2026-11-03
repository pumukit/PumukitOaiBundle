<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Tests\Services;

use PHPUnit\Framework\TestCase;
use Pumukit\OaiBundle\Services\OaiException;
use Pumukit\OaiBundle\Services\ResumptionToken;

/**
 * @internal
 *
 * @coversNothing
 */
class ResumptionTokenTest extends TestCase
{
    public function testTheTokenSurvivesTheRoundTrip(): void
    {
        $token = new ResumptionToken(ResumptionToken::RECORDS, '', 0, 10, 'oai_dc', new \DateTimeImmutable('2026-01-01T00:00:00Z'), new \DateTimeImmutable('2026-02-01T00:00:00Z'), '0123456789abcdef01234567');
        $next = $token->next('fedcba9876543210fedcba98', 4);

        $encoded = $next->encode();
        $decoded = ResumptionToken::decode($encoded, ResumptionToken::RECORDS);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        $this->assertEquals($next, $decoded);
        $this->assertSame(4, $decoded->cursor);
        $this->assertSame('fedcba9876543210fedcba98', $decoded->after);
    }

    public function testTheInvalidTokensAreRejected(): void
    {
        $valid = new ResumptionToken(ResumptionToken::SETS, 'fedcba9876543210fedcba98', 1, 2);
        $tokens = [
            '%%%',
            base64_encode('not json'),
            base64_encode('[]'),
            base64_encode(json_encode(['k' => 'records', 'a' => 'x', 'c' => 0, 't' => 0, 'm' => 'oai_dc', 'f' => null, 'u' => null, 's' => null])),
            base64_encode(json_encode(['k' => 'records', 'a' => 'fedcba9876543210fedcba98', 'c' => -1, 't' => 0, 'm' => 'oai_dc', 'f' => null, 'u' => null, 's' => null])),
            base64_encode(json_encode(['k' => 'records', 'a' => 'fedcba9876543210fedcba98', 'c' => 0, 't' => 0, 'm' => null, 'f' => null, 'u' => null, 's' => null])),
        ];

        foreach ($tokens as $token) {
            try {
                ResumptionToken::decode($token, ResumptionToken::RECORDS);
                $this->fail($token);
            } catch (OaiException $exception) {
                $this->assertSame('badResumptionToken', $exception->oaiCode);
            }
        }

        $this->expectException(OaiException::class);
        ResumptionToken::decode($valid->encode(), ResumptionToken::RECORDS);
    }
}
