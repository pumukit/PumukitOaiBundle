<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Services;

use Pumukit\BasePlayerBundle\Services\TrackUrlService;
use Pumukit\SchemaBundle\Document\EmbeddedTag;
use Pumukit\SchemaBundle\Document\Exception\PathException;
use Pumukit\SchemaBundle\Document\MediaType\Media;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\PicService;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RecordWriter
{
    public const OAI_DC_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
    public const DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';
    public const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    public function __construct(
        private readonly OaiConfiguration $configuration,
        private readonly PicService $picService,
        private readonly TrackUrlService $trackUrlService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function datestamp(MultimediaObject $record): \DateTimeInterface
    {
        return $record->isUpdatedAt() ?? $record->getPublicDate();
    }

    public static function utc(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function clean(?string $value): string
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
    }

    public static function element(\XMLWriter $xml, string $name, ?string $value): void
    {
        $value = self::clean($value);
        if ('' === trim($value)) {
            return;
        }

        $xml->writeElement($name, $value);
    }

    public function header(\XMLWriter $xml, MultimediaObject $record): void
    {
        $xml->startElement('header');
        $xml->writeElement('identifier', $record->getId());
        $xml->writeElement('datestamp', self::utc(self::datestamp($record)));
        if (null !== $record->getSeries()) {
            $xml->writeElement('setSpec', $record->getSeries()->getId());
        }
        $xml->endElement();
    }

    public function record(\XMLWriter $xml, MultimediaObject $record): void
    {
        $xml->startElement('record');
        $this->header($xml, $record);
        $xml->startElement('metadata');
        $xml->startElement('oai_dc:dc');
        $xml->writeAttribute('xmlns:oai_dc', self::OAI_DC_NAMESPACE);
        $xml->writeAttribute('xmlns:dc', self::DC_NAMESPACE);
        $xml->writeAttribute('xmlns:xsi', self::XSI_NAMESPACE);
        $xml->writeAttribute('xsi:schemaLocation', self::OAI_DC_NAMESPACE.' http://www.openarchives.org/OAI/2.0/oai_dc.xsd');

        $locale = $this->configuration->locale;
        self::element($xml, 'dc:title', $record->getTitle($locale));
        foreach ($record->getPeopleByRoleCod($this->configuration->roleForDcCreator, true) as $person) {
            self::element($xml, 'dc:creator', $person->getName());
        }
        foreach ($record->getTags() as $tag) {
            self::element($xml, 'dc:subject', $this->subject($tag));
        }
        self::element($xml, 'dc:description', $record->getDescription($locale));
        if ($this->configuration->useCopyrightAsDcPublisher) {
            self::element($xml, 'dc:publisher', $record->getCopyright());
        }
        self::element($xml, 'dc:date', $record->getPublicDate()->format('Y-m-d'));
        self::element($xml, 'dc:type', $this->type($record));
        foreach ($this->formats($record) as $format) {
            self::element($xml, 'dc:format', $format);
        }
        foreach ($this->identifiers($record) as $identifier) {
            self::element($xml, 'dc:identifier', $identifier);
        }
        foreach ($this->languages($record) as $language) {
            self::element($xml, 'dc:language', $language);
        }
        self::element($xml, 'dc:rights', $this->configuration->useLicenseAsDcRights ? $record->getLicense() : $record->getCopyright());
        if ($this->configuration->useDcThumbnail) {
            self::element($xml, 'dc:thumbnail', $this->picService->getFirstUrlPic($record, true));
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    private function subject(EmbeddedTag $tag): string
    {
        $title = $tag->getTitle($this->configuration->locale);

        return match ($this->configuration->dcSubjectFormat) {
            'e-ciencia' => sprintf('%s %s', $this->eCienciaCode($tag), $title),
            'all' => sprintf('%s - %s', $tag->getCod(), $title),
            'code' => $tag->getCod(),
            default => $title,
        };
    }

    private function eCienciaCode(EmbeddedTag $tag): string
    {
        $code = $tag->getCod();
        if (!$tag->isDescendantOfByCod('UNESCO') && !str_starts_with($code, 'U9')) {
            return $code;
        }

        return match ($tag->getLevel()) {
            3 => substr($code, 1, 2),
            4 => substr($code, 1, 4),
            5 => sprintf('%s.%s', substr($code, 1, 4), substr($code, 5, 2)),
            default => (string) $tag->getLevel(),
        };
    }

    private function type(MultimediaObject $record): string
    {
        return match (true) {
            $record->isAudioType() => $this->configuration->audioDcType,
            $record->isImageType() => $this->configuration->imageDcType,
            $record->isDocumentType() => $this->configuration->docDcType,
            default => $this->configuration->videoDcType,
        };
    }

    private function formats(MultimediaObject $record): array
    {
        $formats = [];
        foreach ([$record->tracks(), $record->documents(), $record->images()] as $collection) {
            foreach ($this->visible($collection) as $media) {
                $path = (string) $media->getUrl();
                $extension = strtolower(pathinfo((string) (parse_url($path, PHP_URL_PATH) ?? $path), PATHINFO_EXTENSION));
                $mimeType = '' === $extension ? null : (MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null);
                if (null !== $mimeType) {
                    $formats[$mimeType] = $mimeType;
                }
            }
        }

        return array_values($formats);
    }

    private function identifiers(MultimediaObject $record): array
    {
        $mapping = $this->configuration->dcIdentifierUrlMapping;
        $identifiers = [];
        if (in_array($mapping, ['all', 'iframe'], true)) {
            $identifiers[] = $this->urlGenerator->generate('pumukit_webtv_multimediaobject_iframe', ['id' => $record->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        if (in_array($mapping, ['all', 'portal_and_track', 'portal'], true)) {
            $identifiers[] = $this->urlGenerator->generate('pumukit_webtv_multimediaobject_index', ['id' => $record->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        if (in_array($mapping, ['all', 'portal_and_track', 'track'], true)) {
            foreach ($this->visible($record->tracks()) as $track) {
                try {
                    $identifiers[] = $this->trackUrlService->generateTrackFileUrl($track, UrlGeneratorInterface::ABSOLUTE_URL);
                } catch (PathException) {
                }
            }
        }

        return $identifiers;
    }

    private function languages(MultimediaObject $record): array
    {
        $languages = [];
        foreach ($this->visible($record->tracks()) as $track) {
            $language = (string) $track->language();
            if ('' === $language || isset($languages[$language])) {
                continue;
            }

            try {
                $languages[$language] = [$language, Languages::getAlpha3Code($language)];
            } catch (MissingResourceException) {
                $languages[$language] = [$language];
            }
        }

        return array_merge(...array_values($languages));
    }

    private function visible(?iterable $collection): iterable
    {
        foreach ($collection ?? [] as $media) {
            if ($media instanceof Media && !$media->isHide() && $media->tags()->containsTag($this->configuration->displayTrackTag)) {
                yield $media;
            }
        }
    }
}
