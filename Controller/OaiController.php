<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\BasePlayerBundle\Services\TrackUrlService;
use Pumukit\OaiBundle\Utils\Iso639Convert;
use Pumukit\OaiBundle\Utils\ResumptionToken;
use Pumukit\OaiBundle\Utils\ResumptionTokenException;
use Pumukit\OaiBundle\Utils\SimpleXMLExtended;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Services\PicService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class OaiController extends AbstractController
{
    private $documentManager;
    private $picService;
    private $trackService;
    private $pumukitInfo;
    private $pumukitOAIUseDcThumbnail;
    private $pumukitOAIDcIdentifierUrlMapping;
    private $pumukitOAIAudioDcType;
    private $pumukitOAIVideoDcType;
    private $pumukitOAIImageDcType;
    private $pumukitOAIDocDcType;
    private $pumukitOAIDcSubjectFormat;
    private $pumukitOAIUseCopyrightAsDcPublisher;
    private $pumukitOAIRoleForDcCreator;
    private $pumukitOAIUseLicenseAsDcRights;

    public function __construct(
        DocumentManager $documentManager,
        PicService $picService,
        TrackUrlService $trackService,
        $pumukitInfo,
        $pumukitOAIUseDcThumbnail,
        $pumukitOAIDcIdentifierUrlMapping,
        $pumukitOAIAudioDcType,
        $pumukitOAIVideoDcType,
        $pumukitOAIImageDcType,
        $pumukitOAIDocDcType,
        $pumukitOAIDcSubjectFormat,
        $pumukitOAIUseCopyrightAsDcPublisher,
        $pumukitOAIRoleForDcCreator,
        $pumukitOAIUseLicenseAsDcRights
    ) {
        $this->documentManager = $documentManager;
        $this->picService = $picService;
        $this->trackService = $trackService;
        $this->pumukitInfo = $pumukitInfo;
        $this->pumukitOAIUseDcThumbnail = $pumukitOAIUseDcThumbnail;
        $this->pumukitOAIDcIdentifierUrlMapping = $pumukitOAIDcIdentifierUrlMapping;
        $this->pumukitOAIAudioDcType = $pumukitOAIAudioDcType;
        $this->pumukitOAIVideoDcType = $pumukitOAIVideoDcType;
        $this->pumukitOAIImageDcType = $pumukitOAIImageDcType;
        $this->pumukitOAIDocDcType = $pumukitOAIDocDcType;
        $this->pumukitOAIDcSubjectFormat = $pumukitOAIDcSubjectFormat;
        $this->pumukitOAIUseCopyrightAsDcPublisher = $pumukitOAIUseCopyrightAsDcPublisher;
        $this->pumukitOAIRoleForDcCreator = $pumukitOAIRoleForDcCreator;
        $this->pumukitOAIUseLicenseAsDcRights = $pumukitOAIUseLicenseAsDcRights;
    }

    /**
     * @Route("/oai.xml", defaults={"_format": "xml"}, name="pumukit_oai_index")
     */
    public function indexAction(Request $request)
    {
        switch ($request->query->get('verb')) {
            case 'Identify':
                return $this->identify();

            case 'ListMetadataFormats':
                return $this->listMetadataFormats($request);

            case 'ListSets':
                return $this->listSets($request);

            case 'ListIdentifiers':
            case 'ListRecords':
                return $this->listIdentifiers($request);

            case 'GetRecord':
                return $this->getRecord($request);

            default:
                return $this->error('badVerb', 'Illegal OAI verb');
        }
    }

    public function getRecord(Request $request): Response
    {
        if ('oai_dc' !== $request->query->get('metadataPrefix')) {
            return $this->error('cannotDisseminateFormat', 'cannotDisseminateFormat');
        }

        $identifier = $request->query->get('identifier');

        $mmObjColl = $this->documentManager->getRepository(MultimediaObject::class);
        $object = $mmObjColl->find(['id' => $identifier]);

        if (null === $object) {
            return $this->error('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository');
        }

        $request = '<request>'.$this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL).'</request>';
        $XMLrequest = new SimpleXMLExtended($request);
        $XMLrequest->addAttribute('verb', 'GetRecord');
        $XMLrequest->addAttribute('identifier', $identifier);
        $XMLrequest->addAttribute('metadataPrefix', 'oai_dc');

        $XMLgetRecord = new SimpleXMLExtended('<GetRecord></GetRecord>');
        $XMLrecord = $XMLgetRecord->addChild('record');
        $this->genObjectHeader($XMLrecord, $object);
        $this->genObjectMetadata($XMLrecord, $object);

        return $this->genResponse($XMLrequest, $XMLgetRecord);
    }

    public function listIdentifiers(Request $request): Response
    {
        $verb = $request->query->get('verb');
        $limit = 10;

        try {
            $token = $this->getResumptionToken($request);
        } catch (ResumptionTokenException $e) {
            return $this->error('badResumptionToken', 'The value of the resumptionToken argument is invalid or expired');
        } catch (\Exception $e) {
            return $this->error('badArgument', 'The request includes illegal arguments, is missing required arguments, includes a repeated argument, or values for arguments have an illegal syntax');
        }

        if ('oai_dc' !== $token->getMetadataPrefix()) {
            return $this->error('cannotDisseminateFormat', 'cannotDisseminateFormat');
        }

        $mmObjColl = $this->filter($limit, $token->getOffset(), $token->getFrom(), $token->getUntil(), $token->getSet());

        if (0 === (is_countable($mmObjColl) ? count($mmObjColl) : 0)) {
            return $this->error('noRecordsMatch', 'The combination of the values of the from, until, and set arguments results in an empty list');
        }

        $XMLrequestText = '<request>'.$this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL).'</request>';
        $XMLrequest = new SimpleXMLExtended($XMLrequestText);
        $XMLrequest->addAttribute('metadataPrefix', 'oai_dc');
        if ($token->getFrom()) {
            $XMLrequest->addAttribute('from', $token->getFrom()->format('Y-m-d'));
        }
        if ($token->getUntil()) {
            $XMLrequest->addAttribute('until', $token->getUntil()->format('Y-m-d'));
        }
        if ($token->getSet()) {
            $XMLrequest->addAttribute('set', $token->getSet());
        }

        $XMLrequest->addAttribute('verb', $verb);
        if ('ListIdentifiers' === $verb) {
            $XMLlist = new SimpleXMLExtended('<ListIdentifiers></ListIdentifiers>');
            foreach ($mmObjColl as $object) {
                $this->genObjectHeader($XMLlist, $object);
            }
        } else {
            $XMLlist = new SimpleXMLExtended('<ListRecords></ListRecords>');
            foreach ($mmObjColl as $object) {
                $XMLrecord = $XMLlist->addChild('record');
                $this->genObjectHeader($XMLrecord, $object);
                $this->genObjectMetadata($XMLrecord, $object);
            }
        }

        $next = $token->next();
        $cursor = $limit * $next->getOffset();
        $count = is_countable($mmObjColl) ? count($mmObjColl) : 0;

        if ($cursor < $count) {
            $XMLresumptionToken = $XMLlist->addChild('resumptionToken', $next->encode());
            $XMLresumptionToken->addAttribute('expirationDate', '2222-06-01T23:20:00Z');
            $XMLresumptionToken->addAttribute('completeListSize', (string) $count);
            $XMLresumptionToken->addAttribute('cursor', $cursor < $count ? (string) $cursor : (string) $count);
        }

        return $this->genResponse($XMLrequest, $XMLlist);
    }

    protected function error($cod, $msg = ''): Response
    {
        $request = '<request>'.$this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL).'</request>';
        $XMLrequest = new SimpleXMLExtended($request);

        $error = '<error>'.$msg.'</error>';
        $XMLerror = new SimpleXMLExtended($error);
        $XMLerror->addAttribute('code', $cod);

        return $this->genResponse($XMLrequest, $XMLerror);
    }

    protected function filter($limit, $offset, \DateTime $from = null, \DateTime $until = null, $set = null)
    {
        $multimediaObjectRepo = $this->documentManager->getRepository(MultimediaObject::class);
        $seriesRepo = $this->documentManager->getRepository(Series::class);

        $queryBuilder = $multimediaObjectRepo
            ->createStandardQueryBuilder()
            ->limit($limit)
            ->skip($limit * $offset)
        ;

        if ($from) {
            $queryBuilder->field('public_date')->gte($from);
        }

        if ($until) {
            $queryBuilder->field('public_date')->lte($until);
        }

        if ($set && '_all_' !== $set) {
            $series = $seriesRepo->find(['id' => $set]);
            if (!$series) {
                return [];
            }
            $queryBuilder->field('series')->references($series);
        }

        return $queryBuilder->getQuery()->execute();
    }

    private function identify(): Response
    {
        $request = '<request>'.$this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL).'</request>';
        $XMLrequest = new SimpleXMLExtended($request);
        $XMLrequest->addAttribute('verb', 'Identify');

        $XMLidentify = new SimpleXMLExtended('<Identify></Identify>');
        $info = $this->pumukitInfo;
        $XMLidentify->addChild('repositoryName', $info['description']);
        $XMLidentify->addChild('baseURL', $this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL));
        $XMLidentify->addChild('protocolVersion', '2.0');
        $XMLidentify->addChild('adminEmail', $info['email']);
        $XMLidentify->addChild('earliestDatestamp', '1990-02-01T12:00:00Z');
        $XMLidentify->addChild('deletedRecord', 'no');
        $XMLidentify->addChild('granularity', 'YYYY-MM-DDThh:mm:ssZ');

        return $this->genResponse($XMLrequest, $XMLidentify);
    }

    private function listMetadataFormats($request)
    {
        $identifier = $request->query->get('identifier');

        $mmObjColl = $this->documentManager->getRepository(MultimediaObject::class);
        $mmObj = $mmObjColl->find(['id' => $identifier]);

        if ($request->query->has('identifier') && null === $mmObj) {
            return $this->error('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository');
        }

        $XMLrequestText = '<request>'.$this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL).'</request>';
        $XMLrequest = new SimpleXMLExtended($XMLrequestText);
        $XMLrequest->addAttribute('verb', 'ListMetadataFormats');
        if ($request->query->has('identifier')) {
            $XMLrequest->addAttribute('identifier', $identifier);
        }

        $XMLlistMetadataFormats = new SimpleXMLExtended('<ListMetadataFormats></ListMetadataFormats>');
        $XMLmetadataFormat = $XMLlistMetadataFormats->addChild('metadataFormat');
        $XMLmetadataFormat->addChild('metadataPrefix', 'oai_dc');
        $XMLmetadataFormat->addChild('schema', 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd');
        $XMLmetadataFormat->addChild('metadataNamespace', 'http://www.openarchives.org/OAI/2.0/oai_dc/');

        return $this->genResponse($XMLrequest, $XMLlistMetadataFormats);
    }

    private function listSets($request)
    {
        $limit = 10;

        try {
            $token = $this->getResumptionToken($request);
        } catch (ResumptionTokenException $e) {
            return $this->error('badResumptionToken', 'The value of the resumptionToken argument is invalid or expired');
        } catch (\Exception $e) {
            return $this->error('badArgument', 'The request includes illegal arguments, is missing required arguments, includes a repeated argument, or values for arguments have an illegal syntax');
        }

        $allSeries = $this->documentManager->getRepository(Series::class);
        $allSeries = $allSeries
            ->createQueryBuilder()
            ->limit($limit)
            ->skip($limit * $token->getOffset())
            ->getQuery()
            ->execute()
        ;

        $request = '<request>'.$this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL).'</request>';
        $XMLrequest = new SimpleXMLExtended($request);
        $XMLrequest->addAttribute('verb', 'ListSets');

        $XMLlistSets = new SimpleXMLExtended('<ListSets></ListSets>');
        foreach ($allSeries as $series) {
            $XMLset = $XMLlistSets->addChild('set');

            /** @var SimpleXMLExtended */
            $XMLsetSpec = $XMLset->addChild('setSpec');
            $XMLsetSpec->addCDATA($series->getId());

            /** @var SimpleXMLExtended */
            $XMLsetName = $XMLset->addChild('setName');
            $XMLsetName->addCDATA($series->getTitle());
        }

        $next = $token->next();
        $cursor = $limit * $next->getOffset();
        $count = null === $allSeries ? 0 : count($allSeries);

        if ($cursor < $count) {
            $XMLresumptionToken = $XMLlistSets->addChild('resumptionToken', $next->encode());
            $XMLresumptionToken->addAttribute('expirationDate', '2222-06-01T23:20:00Z');
            $XMLresumptionToken->addAttribute('completeListSize', (string) $count);
            $XMLresumptionToken->addAttribute('cursor', $cursor < $count ? (string) $cursor : (string) $count);
        }

        return $this->genResponse($XMLrequest, $XMLlistSets);
    }

    private function genObjectHeader($XMLlist, $object)
    {
        $XMLheader = $XMLlist->addChild('header');

        /** @var SimpleXMLExtended */
        $XMLidentifier = $XMLheader->addChild('identifier');
        $XMLidentifier->addCDATA($object->getId());
        $XMLheader->addChild('datestamp', $object->getPublicDate()->format('Y-m-d'));

        /** @var SimpleXMLExtended */
        $XMLsetSpec = $XMLheader->addChild('setSpec');
        $XMLsetSpec->addCDATA($object->getSeries()->getId());

        return $XMLheader;
    }

    private function genObjectMetadata($XMLlist, $object): void
    {
        $XMLmetadata = $XMLlist->addChild('metadata');

        $XMLoai_dc = new SimpleXMLExtended('<oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd"></oai_dc:dc>');

        /** @var SimpleXMLExtended */
        $XMLtitle = $XMLoai_dc->addChild('dc:title', '', 'http://purl.org/dc/elements/1.1/');
        $XMLtitle->addCDATA($object->getTitle());

        /** @var SimpleXMLExtended */
        $XMLdescription = $XMLoai_dc->addChild('dc:description', '', 'http://purl.org/dc/elements/1.1/');
        $XMLdescription->addCDATA($object->getDescription());
        $XMLoai_dc->addChild('dc:date', $object->getPublicDate()->format('Y-m-d'), 'http://purl.org/dc/elements/1.1/');
        $XMLoai_dc->addChild('dc:updateAt', $object->isUpdatedAt()->format('Y-m-d H:i:s'), 'http://purl.org/dc/elements/1.1/');

        switch ($this->pumukitOAIDcIdentifierUrlMapping) {
            case 'all':
                $url = $this->generateUrl('pumukit_webtv_multimediaobject_iframe', ['id' => $object->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');
                $url = $this->generateUrl('pumukit_webtv_multimediaobject_index', ['id' => $object->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');
                foreach ($object->getFilteredTracksWithTags(['display']) as $track) {
                    $url = $this->generateTrackFileUrl($track);
                    $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');
                }

                break;

            case 'portal_and_track':
                $url = $this->generateUrl('pumukit_webtv_multimediaobject_index', ['id' => $object->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');
                foreach ($object->getFilteredTracksWithTags(['display']) as $track) {
                    $url = $this->generateTrackFileUrl($track);
                    $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');
                }

                break;

            case 'track':
                foreach ($object->getFilteredTracksWithTags(['display']) as $track) {
                    $url = $this->generateTrackFileUrl($track);
                    $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');
                }

                break;

            case 'iframe':
                $url = $this->generateUrl('pumukit_webtv_multimediaobject_iframe', ['id' => $object->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');

                break;

            default: // portal
                $url = $this->generateUrl('pumukit_webtv_multimediaobject_index', ['id' => $object->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                $XMLoai_dc->addChild('dc:identifier', $url, 'http://purl.org/dc/elements/1.1/');

                break;
        }

        if ($this->pumukitOAIUseDcThumbnail) {
            $thumbnail = $this->picService->getFirstUrlPic($object, true);
            $XMLoai_dc->addChild('dc:thumbnail', $thumbnail, 'http://purl.org/dc/elements/1.1/');
        }

        foreach ($object->getTracksWithTag('display') as $track) {
            $type = $this->pumukitOAIVideoDcType;
            if ($object->isAudioType()) {
                $type = $this->pumukitOAIAudioDcType;
            }
            if ($object->isImageType()) {
                $type = $this->pumukitOAIImageDcType;
            }
            if ($object->isDocumentType()) {
                $type = $this->pumukitOAIDocDcType;
            }
            if ($object->isExternalType()) {
                $type = 'External media';
            }

            $XMLoai_dc->addChild('dc:type', $type, 'http://purl.org/dc/elements/1.1/');
            if (!$object->isExternalType()) {
                $mimeTypes = new MimeTypes();
                $mimeType = $mimeTypes->guessMimeType($track->storage()->path()->path());
                $XMLoai_dc->addChild('dc:format', $mimeType, 'http://purl.org/dc/elements/1.1/');
            }
        }
        foreach ($object->getTags() as $tag) {
            /** @var SimpleXMLExtended */
            $XMLsubject = $XMLoai_dc->addChild('dc:subject', '', 'http://purl.org/dc/elements/1.1/');

            switch ($this->pumukitOAIDcSubjectFormat) {
                case 'e-ciencia':
                    $cod = $tag->getCod();
                    if ($tag->isDescendantOfByCod('UNESCO') || (0 === strpos($tag->getCod(), 'U9'))) {
                        $cod = $tag->getLevel();

                        switch ($tag->getLevel()) {
                            case 3:
                                $cod = substr($tag->getCod(), 1, 2);

                                break;

                            case 4:
                                $cod = substr($tag->getCod(), 1, 4);

                                break;

                            case 5:
                                $cod = sprintf('%s.%s', substr($tag->getCod(), 1, 4), substr($tag->getCod(), 5, 2));

                                break;
                        }
                    }
                    $subject = sprintf('%s %s', $cod, $tag->getTitle());

                    break;

                case 'all':
                    $subject = sprintf('%s - %s', $tag->getCod(), $tag->getTitle());

                    break;

                case 'code':
                    $subject = $tag->getCod();

                    break;

                default: // title
                    $subject = $tag->getTitle();

                    break;
            }
            $XMLsubject->addCDATA($subject);
        }

        if ($this->pumukitOAIUseCopyrightAsDcPublisher) {
            /** @var SimpleXMLExtended */
            $XMLpublisher = $XMLoai_dc->addChild('dc:publisher', '', 'http://purl.org/dc/elements/1.1/');
            $XMLpublisher->addCDATA($object->getCopyright());
        } else {
            /** @var SimpleXMLExtended */
            $XMLpublisher = $XMLoai_dc->addChild('dc:publisher', '', 'http://purl.org/dc/elements/1.1/');
            $XMLpublisher->addCDATA('');
        }

        $people = $object->getPeopleByRoleCod($this->pumukitOAIRoleForDcCreator, true);
        foreach ($people as $person) {
            /** @var SimpleXMLExtended */
            $XMLcreator = $XMLoai_dc->addChild('dc:creator', '', 'http://purl.org/dc/elements/1.1/');
            $XMLcreator->addCDATA($person->getName());
        }

        if ($object->getLocale()) {
            $XMLoai_dc->addChild('dc:language', $object->getLocale(), 'http://purl.org/dc/elements/1.1/');
        }
        if ($codeLocale3 = Iso639Convert::get($object->getLocale())) {
            $XMLoai_dc->addChild('dc:language', $codeLocale3, 'http://purl.org/dc/elements/1.1/');
        }

        if ($this->pumukitOAIUseLicenseAsDcRights) {
            /** @var SimpleXMLExtended */
            $XMLrights = $XMLoai_dc->addChild('dc:rights', '', 'http://purl.org/dc/elements/1.1/');
            $XMLrights->addCDATA($object->getLicense());
        } else {
            /** @var SimpleXMLExtended */
            $XMLrights = $XMLoai_dc->addChild('dc:rights', '', 'http://purl.org/dc/elements/1.1/');
            $XMLrights->addCDATA($object->getCopyright());
        }

        $toDom = dom_import_simplexml($XMLmetadata);
        $fromDom = dom_import_simplexml($XMLoai_dc);
        $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
    }

    private function genResponse($responseXml, $verb): Response
    {
        $XML = new SimpleXMLExtended('<OAI-PMH></OAI-PMH>');
        $XML->addAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/');
        $XML->addAttribute('xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd', 'http://www.w3.org/2001/XMLSchema-instance');
        $XML->addChild('responseDate', date('Y-m-d\\TH:i:s\\Z'));

        $toDom = dom_import_simplexml($XML);
        $fromDom = dom_import_simplexml($responseXml);
        $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
        $XML = simplexml_import_dom($toDom);

        $toDom = dom_import_simplexml($XML);
        $fromDom = dom_import_simplexml($verb);
        $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
        $XML = simplexml_import_dom($toDom);

        return new Response($XML->asXML(), 200, ['Content-Type' => 'text/xml']);
    }

    private function getResumptionToken(Request $request): ResumptionToken
    {
        if ($request->query->has('resumptionToken')) {
            return ResumptionToken::decode($request->query->get('resumptionToken'));
        }

        $from = $request->query->has('from') ?
            \DateTime::createFromFormat('Y-m-d', $request->query->get('from')) :
            null;

        $until = $request->query->has('until') ?
            \DateTime::createFromFormat('Y-m-d', $request->query->get('until')) :
            null;

        return new ResumptionToken(0, $from, $until, $request->query->get('metadataPrefix'), $request->query->get('set'));
    }

    private function generateTrackFileUrl($track)
    {
        return $this->trackService->generateTrackFileUrl($track, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
