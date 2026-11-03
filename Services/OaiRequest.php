<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Services;

use Symfony\Component\HttpFoundation\Request;

final readonly class OaiRequest
{
    public const VERBS = ['Identify', 'ListMetadataFormats', 'ListSets', 'ListIdentifiers', 'ListRecords', 'GetRecord'];

    private function __construct(
        public string $verb,
        public array $arguments,
        private bool $repeated,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $verbs = [];
        $arguments = [];
        $repeated = false;
        foreach (explode('&', self::rawArguments($request)) as $pair) {
            if ('' === $pair) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if ('verb' === $name) {
                $verbs[] = $value;

                continue;
            }
            $repeated = $repeated || array_key_exists($name, $arguments);
            $arguments[$name] = $value;
        }

        if (1 !== count($verbs) || !in_array($verbs[0], self::VERBS, true)) {
            throw OaiException::badVerb();
        }

        return new self($verbs[0], $arguments, $repeated);
    }

    public function validate(array $required, array $optional = [], bool $resumable = false): void
    {
        if ($this->repeated) {
            throw OaiException::badArgument();
        }

        $names = array_keys($this->arguments);
        if ($resumable && $this->has('resumptionToken')) {
            if (['resumptionToken'] !== $names) {
                throw OaiException::badArgument();
            }

            return;
        }

        if ([] !== array_diff($required, $names) || [] !== array_diff($names, [...$required, ...$optional])) {
            throw OaiException::badArgument();
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->arguments);
    }

    public function get(string $name): ?string
    {
        return $this->arguments[$name] ?? null;
    }

    private static function rawArguments(Request $request): string
    {
        if (!$request->isMethod('POST')) {
            return (string) $request->server->get('QUERY_STRING', '');
        }

        $content = (string) $request->getContent();

        return '' !== $content ? $content : http_build_query($request->request->all());
    }
}
