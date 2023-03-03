<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Utils;

class ResumptionToken
{
    private $offset;
    private $from;
    private $until;
    private $metadataPrefix;
    private $set;

    public function __construct(int $offset = 0, ?\DateTime $from = null, ?\DateTime $until = null, ?string $metadataPrefix = null, ?string $set = null)
    {
        $this->offset = $offset;
        $this->from = $from;
        $this->until = $until;
        $this->metadataPrefix = $metadataPrefix;
        $this->set = $set;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getFrom(): ?\DateTime
    {
        return $this->from;
    }

    public function getUntil(): ?\DateTime
    {
        return $this->until;
    }

    public function getMetadataPrefix(): ?string
    {
        return $this->metadataPrefix;
    }

    public function getSet(): ?string
    {
        return $this->set;
    }

    public function encode(): string
    {
        $params = [];
        $params['offset'] = $this->offset;
        $params['metadataPrefix'] = $this->metadataPrefix;
        $params['set'] = $this->set;
        $params['from'] = null;
        $params['until'] = null;

        if ($this->from) {
            $params['from'] = $this->from->getTimestamp();
        }

        if ($this->until) {
            $params['until'] = $this->until->getTimestamp();
        }

        return base64_encode(json_encode($params));
    }

    public function next(): ResumptionToken
    {
        $next = clone $this;
        ++$next->offset;

        return $next;
    }

    public static function decode(string $token): ResumptionToken
    {
        $base64Decode = base64_decode($token, true);
        if (false === $base64Decode) {
            throw new ResumptionTokenException('base64_decode error');
        }
        $params = (array) json_decode(base64_decode($token, true));

        if (json_last_error()) {
            throw new ResumptionTokenException('json_decode error');
        }

        if (!empty($params['from'])) {
            $params['from'] = new \DateTime('@'.$params['from']);
        }

        if (!empty($params['until'])) {
            $params['until'] = new \DateTime('@'.$params['until']);
        }

        return new self($params['offset'], $params['from'], $params['until'], $params['metadataPrefix'], $params['set']);
    }
}
