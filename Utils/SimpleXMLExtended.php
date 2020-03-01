<?php

namespace Pumukit\OaiBundle\Utils;

use SimpleXMLElement;

class SimpleXMLExtended extends SimpleXMLElement
{
    public function addCDATA($cData): void
    {
        $node = dom_import_simplexml($this);
        $no = $node->ownerDocument;
        $node->appendChild($no->createCDATASection($cData));
    }
}
