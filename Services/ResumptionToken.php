<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Services;

final readonly class ResumptionToken
{
    public const RECORDS = 'records';
    public const SETS = 'sets';
    private const ID = '/^[a-f\d]{24}$/';

    public function __construct(
        public string $kind,
        public string $after,
        public int $cursor,
        public int $total,
        public ?string $metadataPrefix = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $until = null,
        public ?string $set = null,
    ) {}

    public function encode(): string
    {
        $json = json_encode([
            'k' => $this->kind,
            'a' => $this->after,
            'c' => $this->cursor,
            't' => $this->total,
            'm' => $this->metadataPrefix,
            'f' => $this->from?->getTimestamp(),
            'u' => $this->until?->getTimestamp(),
            's' => $this->set,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $token, string $kind): self
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $params = false === $json ? null : json_decode($json, true);

        if (!is_array($params)
            || $kind !== ($params['k'] ?? null)
            || !is_string($params['a'] ?? null) || !preg_match(self::ID, $params['a'])
            || !is_int($params['c'] ?? null) || $params['c'] < 0
            || !is_int($params['t'] ?? null) || $params['t'] < 0
            || !self::nullable($params, 'm', 'is_string')
            || !self::nullable($params, 'f', 'is_int')
            || !self::nullable($params, 'u', 'is_int')
            || !self::nullable($params, 's', 'is_string')
            || (self::RECORDS === $kind && null === $params['m'])
        ) {
            throw OaiException::badResumptionToken();
        }

        return new self(
            $kind,
            $params['a'],
            $params['c'],
            $params['t'],
            $params['m'],
            null === $params['f'] ? null : new \DateTimeImmutable('@'.$params['f']),
            null === $params['u'] ? null : new \DateTimeImmutable('@'.$params['u']),
            $params['s'],
        );
    }

    public function next(string $after, int $count): self
    {
        return new self($this->kind, $after, $this->cursor + $count, $this->total, $this->metadataPrefix, $this->from, $this->until, $this->set);
    }

    private static function nullable(array $params, string $key, callable $type): bool
    {
        return array_key_exists($key, $params) && (null === $params[$key] || $type($params[$key]));
    }
}
