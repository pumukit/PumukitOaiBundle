<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Services;

final readonly class OaiConfiguration
{
    public function __construct(
        public bool $listOnlyPublishedObjects,
        public string $pubChannelTag,
        public string $displayTrackTag,
        public int $pageSize,
        public bool $useDcThumbnail,
        public string $videoDcType,
        public string $audioDcType,
        public string $docDcType,
        public string $imageDcType,
        public ?string $roleForDcCreator,
        public bool $useLicenseAsDcRights,
        public bool $useCopyrightAsDcPublisher,
        public string $dcSubjectFormat,
        public string $dcIdentifierUrlMapping,
        public string $locale,
        public array $pumukitInfo,
    ) {}
}
