<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pumukit\OaiBundle\Controller\OaiController;
use Pumukit\OaiBundle\DependencyInjection\PumukitOaiExtension;
use Pumukit\OaiBundle\Routing\RouteLoader;
use Pumukit\OaiBundle\Services\OaiService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 *
 * @coversNothing
 */
class PumukitOaiExtensionTest extends TestCase
{
    public function testTheDisabledBundleOnlyRegistersTheRouteLoader(): void
    {
        $container = $this->load(['enable' => false]);

        $this->assertSame([RouteLoader::class], $this->oaiServices($container));
        $this->assertSame('%pumukitoai.enable%', $container->getDefinition(RouteLoader::class)->getArgument('$enabled'));
        $this->assertTrue($container->getDefinition(RouteLoader::class)->hasTag('routing.loader'));
    }

    public function testTheEnabledBundleRegistersItsServices(): void
    {
        $container = $this->load([]);

        foreach ([OaiController::class, OaiService::class, RouteLoader::class] as $id) {
            $this->assertTrue($container->hasDefinition($id), $id);
        }
    }

    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new PumukitOaiExtension())->load([$config], $container);

        return $container;
    }

    private function oaiServices(ContainerBuilder $container): array
    {
        return array_values(array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_starts_with($id, 'Pumukit\OaiBundle\\')));
    }
}
