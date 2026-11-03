<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Services;

final class OaiException extends \RuntimeException
{
    private function __construct(public readonly string $oaiCode, string $message)
    {
        parent::__construct($message);
    }

    public static function badVerb(): self
    {
        return new self('badVerb', 'Value of the verb argument is not a legal OAI-PMH verb, the verb argument is missing, or the verb argument is repeated.');
    }

    public static function badArgument(): self
    {
        return new self('badArgument', 'The request includes illegal arguments, is missing required arguments, includes a repeated argument, or values for arguments have an illegal syntax.');
    }

    public static function badResumptionToken(): self
    {
        return new self('badResumptionToken', 'The value of the resumptionToken argument is invalid or expired.');
    }

    public static function cannotDisseminateFormat(): self
    {
        return new self('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
    }

    public static function idDoesNotExist(): self
    {
        return new self('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
    }

    public static function noRecordsMatch(): self
    {
        return new self('noRecordsMatch', 'The combination of the values of the from, until, set and metadataPrefix arguments results in an empty list.');
    }

    public function echoesRequest(): bool
    {
        return !in_array($this->oaiCode, ['badVerb', 'badArgument'], true);
    }
}
