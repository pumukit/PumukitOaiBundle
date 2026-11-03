<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Services;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;

final class OaiService
{
    public const OAI_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/';
    public const METADATA_PREFIX = 'oai_dc';
    private const ALL_SETS = '_all_';

    public function __construct(
        private readonly RecordRepository $repository,
        private readonly RecordWriter $writer,
        private readonly OaiConfiguration $configuration,
    ) {}

    public function handle(OaiRequest $request, string $baseUrl): string
    {
        try {
            return match ($request->verb) {
                'Identify' => $this->identify($request, $baseUrl),
                'ListMetadataFormats' => $this->listMetadataFormats($request, $baseUrl),
                'ListSets' => $this->listSets($request, $baseUrl),
                'GetRecord' => $this->getRecord($request, $baseUrl),
                default => $this->listRecords($request, $baseUrl),
            };
        } catch (OaiException $exception) {
            return $this->error($exception, $baseUrl, $request);
        }
    }

    public function error(OaiException $exception, string $baseUrl, ?OaiRequest $request = null): string
    {
        $xml = $this->open($baseUrl, $exception->echoesRequest() ? $request : null);
        $xml->startElement('error');
        $xml->writeAttribute('code', $exception->oaiCode);
        $xml->text($exception->getMessage());
        $xml->endElement();

        return $this->close($xml);
    }

    private function identify(OaiRequest $request, string $baseUrl): string
    {
        $request->validate([]);
        $info = $this->configuration->pumukitInfo;
        $earliest = $this->repository->earliestDatestamp();

        $xml = $this->open($baseUrl, $request);
        $xml->startElement('Identify');
        $xml->writeElement('repositoryName', RecordWriter::clean($info['description'] ?? $info['title'] ?? 'PuMuKIT'));
        $xml->writeElement('baseURL', $baseUrl);
        $xml->writeElement('protocolVersion', '2.0');
        $xml->writeElement('adminEmail', RecordWriter::clean($info['email'] ?? ''));
        $xml->writeElement('earliestDatestamp', null === $earliest ? '1970-01-01T00:00:00Z' : RecordWriter::utc($earliest));
        $xml->writeElement('deletedRecord', 'no');
        $xml->writeElement('granularity', 'YYYY-MM-DDThh:mm:ssZ');
        $xml->endElement();

        return $this->close($xml);
    }

    private function listMetadataFormats(OaiRequest $request, string $baseUrl): string
    {
        $request->validate([], ['identifier']);
        if ($request->has('identifier') && !$this->repository->find($request->get('identifier')) instanceof MultimediaObject) {
            throw OaiException::idDoesNotExist();
        }

        $xml = $this->open($baseUrl, $request);
        $xml->startElement('ListMetadataFormats');
        $xml->startElement('metadataFormat');
        $xml->writeElement('metadataPrefix', self::METADATA_PREFIX);
        $xml->writeElement('schema', 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd');
        $xml->writeElement('metadataNamespace', RecordWriter::OAI_DC_NAMESPACE);
        $xml->endElement();
        $xml->endElement();

        return $this->close($xml);
    }

    private function listSets(OaiRequest $request, string $baseUrl): string
    {
        $request->validate([], [], true);
        $resumed = $request->has('resumptionToken');
        $token = $resumed
            ? ResumptionToken::decode($request->get('resumptionToken'), ResumptionToken::SETS)
            : new ResumptionToken(ResumptionToken::SETS, '', 0, $this->repository->seriesCount());

        [$page, $more] = $this->slice($this->repository->seriesPage($token->after, $this->configuration->pageSize + 1));

        $xml = $this->open($baseUrl, $request);
        $xml->startElement('ListSets');
        foreach ($page as $series) {
            $xml->startElement('set');
            $xml->writeElement('setSpec', $series->getId());
            $title = RecordWriter::clean($series->getTitle($this->configuration->locale));
            $xml->writeElement('setName', '' === trim($title) ? $series->getId() : $title);
            $xml->endElement();
        }
        $this->resumptionToken($xml, $token, $page, $more, $resumed);
        $xml->endElement();

        return $this->close($xml);
    }

    private function getRecord(OaiRequest $request, string $baseUrl): string
    {
        $request->validate(['identifier', 'metadataPrefix']);
        if (self::METADATA_PREFIX !== $request->get('metadataPrefix')) {
            throw OaiException::cannotDisseminateFormat();
        }

        $record = $this->repository->find($request->get('identifier'));
        if (!$record instanceof MultimediaObject) {
            throw OaiException::idDoesNotExist();
        }

        $xml = $this->open($baseUrl, $request);
        $xml->startElement('GetRecord');
        $this->writer->record($xml, $record);
        $xml->endElement();

        return $this->close($xml);
    }

    private function listRecords(OaiRequest $request, string $baseUrl): string
    {
        $request->validate(['metadataPrefix'], ['from', 'until', 'set'], true);
        $resumed = $request->has('resumptionToken');
        $token = $resumed
            ? ResumptionToken::decode($request->get('resumptionToken'), ResumptionToken::RECORDS)
            : $this->firstToken($request);

        [$page, $more] = $this->slice($this->repository->page($token, $this->configuration->pageSize + 1));
        if ([] === $page) {
            throw OaiException::noRecordsMatch();
        }

        $xml = $this->open($baseUrl, $request);
        $xml->startElement($request->verb);
        foreach ($page as $record) {
            if ('ListIdentifiers' === $request->verb) {
                $this->writer->header($xml, $record);
            } else {
                $this->writer->record($xml, $record);
            }
        }
        $this->resumptionToken($xml, $token, $page, $more, $resumed);
        $xml->endElement();

        return $this->close($xml);
    }

    private function firstToken(OaiRequest $request): ResumptionToken
    {
        [$from, $fromGranularity] = $this->date($request->get('from'), false);
        [$until, $untilGranularity] = $this->date($request->get('until'), true);
        if (($from && $until && $fromGranularity !== $untilGranularity) || ($from && $until && $from >= $until)) {
            throw OaiException::badArgument();
        }

        if (self::METADATA_PREFIX !== $request->get('metadataPrefix')) {
            throw OaiException::cannotDisseminateFormat();
        }

        $set = $request->get('set');
        if (self::ALL_SETS === $set) {
            $set = null;
        }
        if (null !== $set && !$this->repository->series($set) instanceof Series) {
            throw OaiException::noRecordsMatch();
        }

        $token = new ResumptionToken(ResumptionToken::RECORDS, '', 0, 0, self::METADATA_PREFIX, $from, $until, $set);
        $total = $this->repository->count($token);
        if (0 === $total) {
            throw OaiException::noRecordsMatch();
        }

        return new ResumptionToken(ResumptionToken::RECORDS, '', 0, $total, self::METADATA_PREFIX, $from, $until, $set);
    }

    private function date(?string $value, bool $until): array
    {
        if (null === $value) {
            return [null, null];
        }

        $utc = new \DateTimeZone('UTC');
        foreach (['day' => ['Y-m-d', '+1 day'], 'seconds' => ['Y-m-d\TH:i:s\Z', '+1 second']] as $granularity => [$format, $step]) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value, $utc);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $value) {
                return [$until ? $date->modify($step) : $date, $granularity];
            }
        }

        throw OaiException::badArgument();
    }

    private function slice(array $items): array
    {
        $more = count($items) > $this->configuration->pageSize;

        return [array_slice($items, 0, $this->configuration->pageSize), $more];
    }

    private function resumptionToken(\XMLWriter $xml, ResumptionToken $token, array $page, bool $more, bool $resumed): void
    {
        if (!$more && !$resumed) {
            return;
        }

        $xml->startElement('resumptionToken');
        $xml->writeAttribute('completeListSize', (string) max($token->total, $token->cursor + count($page)));
        $xml->writeAttribute('cursor', (string) $token->cursor);
        if ($more) {
            $xml->text($token->next(end($page)->getId(), count($page))->encode());
        }
        $xml->endElement();
    }

    private function open(string $baseUrl, ?OaiRequest $request): \XMLWriter
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('OAI-PMH');
        $xml->writeAttribute('xmlns', self::OAI_NAMESPACE);
        $xml->writeAttribute('xmlns:xsi', RecordWriter::XSI_NAMESPACE);
        $xml->writeAttribute('xsi:schemaLocation', self::OAI_NAMESPACE.' http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');
        $xml->writeElement('responseDate', gmdate('Y-m-d\TH:i:s\Z'));
        $xml->startElement('request');
        if (null !== $request) {
            $xml->writeAttribute('verb', $request->verb);
            foreach ($request->arguments as $name => $value) {
                $xml->writeAttribute($name, RecordWriter::clean($value));
            }
        }
        $xml->text($baseUrl);
        $xml->endElement();

        return $xml;
    }

    private function close(\XMLWriter $xml): string
    {
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
