<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;
use Symfony\Component\Routing\RouteCollection;

final class RouteLoader extends Loader
{
    public const TYPE = 'pumukit_oai';

    public function __construct(private readonly bool $enabled, ?string $env = null)
    {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = $this->enabled ? $this->import($resource, 'attribute') : new RouteCollection();
        $routes->addResource(new ContainerParametersResource(['pumukitoai.enable' => $this->enabled]));

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
