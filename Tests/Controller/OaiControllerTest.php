<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Tests\Controller;

use Pumukit\OaiBundle\Controller\OaiController;
use Pumukit\OaiBundle\Services\OaiService;
use Pumukit\OaiBundle\Tests\OaiTestCase;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Person;
use Pumukit\SchemaBundle\Document\Role;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @internal
 *
 * @coversNothing
 */
class OaiControllerTest extends OaiTestCase
{
    public function testIdentifyDeclaresTheEarliestDatestamp(): void
    {
        $this->updatedAt($this->record('Old'), '2024-05-06 07:08:09 UTC');
        $this->updatedAt($this->record('Legacy', options: ['date' => '2020-01-02 03:04:05 UTC']), null);

        $xml = $this->get('verb=Identify');

        $this->assertSame([self::BASE_URL], $this->values($xml, '/o:OAI-PMH/o:Identify/o:baseURL'));
        $this->assertSame(['2020-01-02T03:04:05Z'], $this->values($xml, '/o:OAI-PMH/o:Identify/o:earliestDatestamp'));
        $this->assertSame(['YYYY-MM-DDThh:mm:ssZ'], $this->values($xml, '/o:OAI-PMH/o:Identify/o:granularity'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $this->values($xml, '/o:OAI-PMH/o:responseDate')[0]);
        $this->assertSame('Identify', (string) $xml->request['verb']);
    }

    public function testOnlyThePublicVisibleObjectsAreListed(): void
    {
        $listed = [
            $this->record('Published'),
            $this->record('Document', trackTags: [], options: ['type' => MultimediaObject::TYPE_DOCUMENT]),
        ];
        $listed[1]->setProperty('opencast', 'id');
        $this->dm->flush();
        $this->record('Blocked', options: ['status' => MultimediaObject::STATUS_BLOCKED]);
        $this->record('Private', options: ['broadcast' => EmbeddedBroadcast::TYPE_LOGIN]);
        $this->record('Other channel', tags: ['PUCHPODCAST']);
        $this->record('Hidden track', options: ['hide' => true]);
        $this->record('Not displayed', trackTags: ['master']);

        $xml = $this->get('verb=ListIdentifiers&metadataPrefix=oai_dc');

        $this->assertSame(array_map(static fn (MultimediaObject $record): string => $record->getId(), $listed), $this->values($xml, '//o:header/o:identifier'));
        $this->assertSame([], $xml->xpath('//o:resumptionToken'));
    }

    public function testGetRecordWritesTheDublinCore(): void
    {
        $record = $this->record("Waves <1> & more\x07", tags: ['PUCHWEBTV', 'U120000']);
        $record->setDescription('Lecture', $this->locale);
        $record->setCopyright('Teltek');
        $role = new Role();
        $role->setCod('actor');
        $person = new Person();
        $person->setName('Ada');
        $this->dm->persist($role);
        $this->dm->persist($person);
        $record->addPersonWithRole($person, $role);
        $this->dm->flush();
        $this->updatedAt($record, '2026-02-03 04:05:06 UTC');

        $xml = $this->get('verb=GetRecord&metadataPrefix=oai_dc&identifier='.$record->getId());
        $dc = '/o:OAI-PMH/o:GetRecord/o:record/o:metadata/oai_dc:dc/dc:';

        $this->assertSame(['2026-02-03T04:05:06Z'], $this->values($xml, '//o:header/o:datestamp'));
        $this->assertSame([$this->series->getId()], $this->values($xml, '//o:header/o:setSpec'));
        $this->assertSame(['Waves <1> & more'], $this->values($xml, $dc.'title'));
        $this->assertSame(['Ada'], $this->values($xml, $dc.'creator'));
        $this->assertSame(['WebTV', 'Mathematics'], $this->values($xml, $dc.'subject'));
        $this->assertSame(['Lecture'], $this->values($xml, $dc.'description'));
        $this->assertSame([], $this->values($xml, $dc.'publisher'));
        $this->assertSame(['2026-01-01'], $this->values($xml, $dc.'date'));
        $this->assertSame(['Sound'], $this->values($xml, $dc.'type'));
        $this->assertSame(['audio/mpeg'], $this->values($xml, $dc.'format'));
        $this->assertSame(['http://localhost/video/'.$record->getId()], $this->values($xml, $dc.'identifier'));
        $this->assertSame(['es', 'spa'], $this->values($xml, $dc.'language'));
        $this->assertSame(['Teltek'], $this->values($xml, $dc.'rights'));
        $this->assertCount(1, $xml->xpath($dc.'thumbnail'));
        $this->assertSame([], $xml->xpath('//dc:updateAt'));
    }

    public function testThePostRequestsAreAccepted(): void
    {
        $this->client->request('POST', '/oai.xml', ['verb' => 'Identify']);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->errorCode($this->oai($this->client->getResponse()->getContent())));

        $this->client->request('POST', '/oai.xml', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'verb=Identify&verb=Identify');
        $this->assertSame('badVerb', $this->errorCode($this->oai($this->client->getResponse()->getContent())));

        $this->client->request('POST', '/oai.xml', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'verb=ListSets&foo=1&foo=2');
        $this->assertSame('badArgument', $this->errorCode($this->oai($this->client->getResponse()->getContent())));
    }

    public function testTheErrorsAreOaiErrors(): void
    {
        $record = $this->record('Record');
        $hidden = $this->series('Hidden', true);
        $this->dm->flush();

        $errors = [
            '' => 'badVerb',
            'verb=Delete' => 'badVerb',
            'verb=Identify&foo=bar' => 'badArgument',
            'verb=ListRecords' => 'badArgument',
            'verb=ListRecords&metadataPrefix=oai_dc&from=2026-01-01T00:00:00' => 'badArgument',
            'verb=ListRecords&metadataPrefix=oai_dc&from=2026-01-01&until=2026-02-01T00:00:00Z' => 'badArgument',
            'verb=ListRecords&metadataPrefix=oai_dc&from=2026-02-01&until=2026-01-01' => 'badArgument',
            'verb=ListRecords&metadataPrefix=oai_dc&resumptionToken=abc&set=1' => 'badArgument',
            'verb=ListRecords&metadataPrefix=mods' => 'cannotDisseminateFormat',
            'verb=ListRecords&resumptionToken=abc' => 'badResumptionToken',
            'verb=ListSets&resumptionToken=eyJrIjoicmVjb3JkcyJ9' => 'badResumptionToken',
            'verb=ListRecords&metadataPrefix=oai_dc&set=not-an-id' => 'noRecordsMatch',
            'verb=ListRecords&metadataPrefix=oai_dc&set='.$hidden->getId() => 'noRecordsMatch',
            'verb=ListRecords&metadataPrefix=oai_dc&from=2027-01-01T00:00:00Z' => 'noRecordsMatch',
            'verb=GetRecord&metadataPrefix=oai_dc&identifier=not-an-id' => 'idDoesNotExist',
            'verb=GetRecord&metadataPrefix=mods&identifier='.$record->getId() => 'cannotDisseminateFormat',
            'verb=ListMetadataFormats&identifier=0123456789abcdef01234567' => 'idDoesNotExist',
        ];

        foreach ($errors as $query => $code) {
            $xml = $this->get($query);
            $this->assertSame($code, $this->errorCode($xml), $query);
            if (in_array($code, ['badVerb', 'badArgument'], true)) {
                $this->assertCount(0, $xml->request->attributes(), $query);
            }
        }

        $this->assertNull($this->errorCode($this->get('verb=ListRecords&metadataPrefix=oai_dc&set=_all_')));
        $this->assertNull($this->errorCode($this->get('verb=ListMetadataFormats&identifier='.$record->getId())));
    }

    public function testTheDisabledRepositoryGivesNotFound(): void
    {
        $controller = new OaiController(self::getContainer()->get(OaiService::class), false);

        $this->expectException(NotFoundHttpException::class);

        $controller->indexAction(Request::create('/oai.xml?verb=Identify'));
    }

    public function testListSetsHidesTheHiddenSeries(): void
    {
        $this->series('Hidden', true);
        $this->dm->flush();

        $xml = $this->get('verb=ListSets');

        $this->assertSame([$this->series->getId()], $this->values($xml, '//o:set/o:setSpec'));
        $this->assertSame(['Physics & Maths'], $this->values($xml, '//o:set/o:setName'));
    }
}
