<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class FilterListener
{
    private $dm;
    private $listOnlyPublishedObjects;
    private $pubChannelTag;
    private $displayTrackTag;

    public function __construct(
        DocumentManager $documentManager,
        bool $listOnlyPublishedObjects = true,
        string $pubChannelTag = 'PUCHWEBTV',
        string $displayTrackTag = 'display'
    ) {
        $this->dm = $documentManager;
        $this->listOnlyPublishedObjects = $listOnlyPublishedObjects;
        $this->pubChannelTag = $pubChannelTag;
        $this->displayTrackTag = $displayTrackTag;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $req = $event->getRequest();
        if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType() && 'Pumukit\OaiBundle' === substr($req->attributes->get('_controller'), 0, 17)) {
            $filter = $this->dm->getFilterCollection()->enable('frontend');
            $filter->setParameter('pub_channel_tag', $this->pubChannelTag);
            $filter->setParameter('display_track_tag', $this->displayTrackTag);

            if ($this->listOnlyPublishedObjects) {
                $filter->setParameter('status', MultimediaObject::STATUS_PUBLISHED);
            }
        }
    }
}
