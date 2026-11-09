<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Pumukit\OaiBundle\Routing\RouteLoader;

/**
 * @internal
 *
 * @coversNothing
 */
class RouteLoaderTest extends TestCase
{
    public function testNoRouteIsLoadedWhenItIsDisabled(): void
    {
        $this->assertCount(0, (new RouteLoader(false))->load('@PumukitOaiBundle/Controller/', RouteLoader::TYPE));
    }

    public function testItOnlySupportsItsType(): void
    {
        $loader = new RouteLoader(true);

        $this->assertTrue($loader->supports('@PumukitOaiBundle/Controller/', RouteLoader::TYPE));
        $this->assertFalse($loader->supports('@PumukitOaiBundle/Controller/', 'attribute'));
    }
}
