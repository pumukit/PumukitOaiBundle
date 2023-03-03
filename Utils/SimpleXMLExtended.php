<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Utils;

class SimpleXMLExtended extends \SimpleXMLElement
{
    public function addCDATA($cData): void
    {
        $node = dom_import_simplexml($this);
        $no = $node->ownerDocument;
        $node->appendChild($no->createCDATASection($cData));
    }
}
