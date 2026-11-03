<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Tests\Services;

use Pumukit\OaiBundle\Tests\OaiTestCase;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 *
 * @coversNothing
 */
class OaiServiceTest extends OaiTestCase
{
    public function testTheListIsHarvestedPageByPage(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->record('Record '.$i)->getId();
        }
        $this->record('Other channel', tags: ['PUCHPODCAST']);

        $harvested = [];
        $cursors = [];
        $query = 'verb=ListRecords&metadataPrefix=oai_dc';
        do {
            $xml = $this->harvest($query, ['pageSize' => 2]);
            $harvested = [...$harvested, ...$this->values($xml, '//o:record/o:header/o:identifier')];
            $token = $xml->xpath('//o:resumptionToken')[0];
            $this->assertSame('5', (string) $token['completeListSize']);
            $cursors[] = (string) $token['cursor'];
            $query = 'verb=ListRecords&resumptionToken='.rawurlencode((string) $token);
        } while ('' !== (string) $token);

        $this->assertSame($ids, $harvested);
        $this->assertSame(['0', '2', '4'], $cursors);
    }

    public function testTheSetsArePaged(): void
    {
        $second = $this->series('Second');
        $this->dm->flush();

        $xml = $this->harvest('verb=ListSets', ['pageSize' => 1]);
        $this->assertSame([$this->series->getId()], $this->values($xml, '//o:set/o:setSpec'));
        $token = (string) $xml->xpath('//o:resumptionToken')[0];

        $xml = $this->harvest('verb=ListSets&resumptionToken='.rawurlencode($token), ['pageSize' => 1]);
        $this->assertSame([$second->getId()], $this->values($xml, '//o:set/o:setSpec'));
        $this->assertSame('', (string) $xml->xpath('//o:resumptionToken')[0]);
    }

    public function testTheDatesSelectTheUpdatedObjectsAndTheLegacyOnesByPublicDate(): void
    {
        $january = $this->record('January');
        $february = $this->record('February');
        $legacy = $this->record('Legacy', options: ['date' => '2026-01-20 10:00:00 UTC']);
        $set = $this->series('Set');
        $inSet = $this->record('In set', series: $set);
        $this->updatedAt($january, '2026-01-10 12:00:00 UTC');
        $this->updatedAt($february, '2026-02-10 12:00:00 UTC');
        $this->updatedAt($legacy, null);
        $this->updatedAt($inSet, '2026-01-15 12:00:00 UTC');

        $titles = fn (string $query): array => $this->values($this->harvest('verb=ListRecords&metadataPrefix=oai_dc&'.$query), '//dc:title');

        $this->assertSame(['January', 'Legacy', 'In set'], $titles('from=2026-01-01&until=2026-01-31'));
        $this->assertSame(['January'], $titles('until=2026-01-10'));
        $this->assertSame([], $titles('until=2026-01-10T11:59:59Z'));
        $this->assertSame(['February'], $titles('from=2026-02-10T12:00:00Z'));
        $this->assertSame(['In set'], $titles('set='.$set->getId()));
        $this->assertSame(['2026-01-20T10:00:00Z'], $this->values($this->harvest('verb=GetRecord&metadataPrefix=oai_dc&identifier='.$legacy->getId()), '//o:header/o:datestamp'));
    }

    public function testTheDublinCoreOptions(): void
    {
        $record = $this->record('Record', tags: ['PUCHWEBTV', 'U120000'], options: ['type' => MultimediaObject::TYPE_VIDEO]);
        $record->setLicense('CC-BY');
        $record->setCopyright('Teltek');
        $this->dm->flush();

        $xml = $this->harvest('verb=GetRecord&metadataPrefix=oai_dc&identifier='.$record->getId(), [
            'dcSubjectFormat' => 'e-ciencia',
            'useLicenseAsDcRights' => true,
            'useCopyrightAsDcPublisher' => true,
            'useDcThumbnail' => false,
            'videoDcType' => 'Video',
            'dcIdentifierUrlMapping' => 'all',
        ]);

        $this->assertSame(['PUCHWEBTV WebTV', '12 Mathematics'], $this->values($xml, '//dc:subject'));
        $this->assertSame(['CC-BY'], $this->values($xml, '//dc:rights'));
        $this->assertSame(['Teltek'], $this->values($xml, '//dc:publisher'));
        $this->assertSame(['Video'], $this->values($xml, '//dc:type'));
        $this->assertSame([], $xml->xpath('//dc:thumbnail'));
        $identifiers = $this->values($xml, '//dc:identifier');
        $router = self::getContainer()->get('router');
        $this->assertSame([
            $router->generate('pumukit_webtv_multimediaobject_iframe', ['id' => $record->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            $router->generate('pumukit_webtv_multimediaobject_index', ['id' => $record->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ], array_slice($identifiers, 0, 2));
        $this->assertStringContainsString('/trackfile/'.$record->getTracks()->first()->id(), $identifiers[2]);
    }

    public function testAMissingFileDoesNotBreakTheRecord(): void
    {
        $missing = dirname($this->file).'/missing-'.basename($this->file);
        touch($missing);
        $record = $this->record('Missing file', options: ['path' => $missing]);
        unlink($missing);
        $this->dm->clear();

        $xml = $this->harvest('verb=GetRecord&metadataPrefix=oai_dc&identifier='.$record->getId(), ['dcIdentifierUrlMapping' => 'track']);

        $this->assertSame(['Missing file'], $this->values($xml, '//dc:title'));
        $this->assertSame(['audio/mpeg'], $this->values($xml, '//dc:format'));
        $this->assertSame([], $xml->xpath('//dc:identifier'));
    }
}
