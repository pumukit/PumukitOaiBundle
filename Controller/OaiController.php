<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Controller;

use Pumukit\OaiBundle\Services\OaiException;
use Pumukit\OaiBundle\Services\OaiRequest;
use Pumukit\OaiBundle\Services\OaiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class OaiController extends AbstractController
{
    public function __construct(
        private readonly OaiService $oaiService,
        private readonly bool $enabled = true,
    ) {}

    #[Route('/oai.xml', name: 'pumukit_oai_index', defaults: ['_format' => 'xml'], methods: ['GET', 'HEAD', 'POST'])]
    public function indexAction(Request $request): Response
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }

        $baseUrl = $this->generateUrl('pumukit_oai_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $xml = $this->oaiService->handle(OaiRequest::fromRequest($request), $baseUrl);
        } catch (OaiException $exception) {
            $xml = $this->oaiService->error($exception, $baseUrl);
        }

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
