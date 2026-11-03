<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Tests;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Pumukit\BasePlayerBundle\Services\TrackUrlService;
use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\OaiBundle\Services\OaiConfiguration;
use Pumukit\OaiBundle\Services\OaiRequest;
use Pumukit\OaiBundle\Services\OaiService;
use Pumukit\OaiBundle\Services\RecordRepository;
use Pumukit\OaiBundle\Services\RecordWriter;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\VideoAudio;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Pumukit\SchemaBundle\Services\PicService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

abstract class OaiTestCase extends PumukitTestCase
{
    protected const BASE_URL = 'http://localhost/oai.xml';

    protected KernelBrowser $client;
    protected Series $series;
    protected string $file;
    protected string $locale;
    private int $numericalId = 0;

    public function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->dm = self::getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->locale = self::getContainer()->getParameter('kernel.default_locale');
        $this->clearBBDD();

        $root = $this->tag('ROOT');
        $unesco = $this->tag('UNESCO', $root);
        $this->tag('U120000', $unesco, 'Mathematics');
        $this->tag('PUCHWEBTV', $root, 'WebTV');
        $this->tag('PUCHPODCAST', $root, 'Podcast');
        $this->series = $this->series('Physics & Maths');
        $this->dm->flush();

        $this->file = sys_get_temp_dir().'/pumukit-oai-'.bin2hex(random_bytes(4)).'.mp3';
        file_put_contents($this->file, str_repeat('a', 2048));
    }

    public function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    protected function tag(string $code, ?Tag $parent = null, ?string $title = null): Tag
    {
        $tag = new Tag();
        $tag->setCod($code);
        $tag->setTitle($title ?? $code, $this->locale);
        if ($parent instanceof Tag) {
            $tag->setParent($parent);
        }
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }

    protected function series(string $title, bool $hide = false): Series
    {
        $series = new Series();
        $series->setNumericalID(++$this->numericalId);
        $series->setTitle($title, $this->locale);
        $series->setHide($hide);
        $this->dm->persist($series);

        return $series;
    }

    protected function record(string $title, array $tags = ['PUCHWEBTV'], array $trackTags = ['display'], ?Series $series = null, array $options = []): MultimediaObject
    {
        $multimediaObject = new MultimediaObject();
        $multimediaObject->setNumericalID(++$this->numericalId);
        $multimediaObject->setTitle($title, $this->locale);
        $multimediaObject->setStatus($options['status'] ?? MultimediaObject::STATUS_PUBLISHED);
        $multimediaObject->setType($options['type'] ?? MultimediaObject::TYPE_AUDIO);
        $multimediaObject->setPublicDate(new \DateTime($options['date'] ?? '2026-01-01'));
        $multimediaObject->setRecordDate(new \DateTime($options['date'] ?? '2026-01-01'));
        $multimediaObject->setSeries($series ?? $this->series);
        $broadcast = new EmbeddedBroadcast();
        $broadcast->setType($options['broadcast'] ?? EmbeddedBroadcast::TYPE_PUBLIC);
        $multimediaObject->setEmbeddedBroadcast($broadcast);
        foreach ($tags as $code) {
            $multimediaObject->addTag($this->dm->getRepository(Tag::class)->findOneBy(['cod' => $code]));
        }
        if ([] !== $trackTags) {
            $multimediaObject->addTrack($this->track($trackTags, $options['hide'] ?? false, $options['path'] ?? null));
        }
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        return $multimediaObject;
    }

    protected function track(array $tags, bool $hide = false, ?string $path = null): Track
    {
        $path ??= $this->file;

        return Track::create(
            basename($path),
            i18nText::create(['en' => '']),
            'es',
            Tags::create($tags),
            $hide,
            true,
            0,
            Storage::create(StorageUrl::create('/storage/oai/'.basename($path)), Path::create($path)),
            VideoAudio::create('{"format":{"duration":"10.000000","size":"2048"}}')
        );
    }

    protected function updatedAt(MultimediaObject $record, ?string $date): void
    {
        $update = null === $date
            ? ['$unset' => ['updatedAt' => true]]
            : ['$set' => ['updatedAt' => new UTCDateTime(new \DateTimeImmutable($date))]];
        $this->dm->getDocumentCollection(MultimediaObject::class)->updateOne(['_id' => new ObjectId($record->getId())], $update);
        $this->dm->detach($record);
    }

    protected function service(array $overrides = []): OaiService
    {
        $container = self::getContainer();
        $configuration = new OaiConfiguration(...array_merge(get_object_vars($container->get(OaiConfiguration::class)), $overrides));

        return new OaiService(
            new RecordRepository($this->dm, $configuration),
            new RecordWriter($configuration, $container->get(PicService::class), $container->get(TrackUrlService::class), $container->get(UrlGeneratorInterface::class)),
            $configuration,
        );
    }

    protected function harvest(string $query, array $overrides = []): \SimpleXMLElement
    {
        return $this->oai($this->service($overrides)->handle(OaiRequest::fromRequest(Request::create('/oai.xml?'.$query)), self::BASE_URL));
    }

    protected function get(string $query): \SimpleXMLElement
    {
        $this->client->request('GET', '/oai.xml?'.$query);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/xml; charset=UTF-8');

        return $this->oai($this->client->getResponse()->getContent());
    }

    protected function oai(string $content): \SimpleXMLElement
    {
        $xml = new \SimpleXMLElement($content);
        $xml->registerXPathNamespace('o', OaiService::OAI_NAMESPACE);
        $xml->registerXPathNamespace('dc', RecordWriter::DC_NAMESPACE);
        $xml->registerXPathNamespace('oai_dc', RecordWriter::OAI_DC_NAMESPACE);

        return $xml;
    }

    protected function values(\SimpleXMLElement $xml, string $path): array
    {
        return array_map('strval', $xml->xpath($path));
    }

    protected function errorCode(\SimpleXMLElement $xml): ?string
    {
        $error = $xml->xpath('/o:OAI-PMH/o:error');

        return [] === $error ? null : (string) $error[0]['code'];
    }
}
