<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\DependencyInjection;

use Pumukit\OaiBundle\Routing\RouteLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader;

class PumukitOaiExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        foreach ($config as $name => $value) {
            $container->setParameter('pumukitoai.'.$name, $value);
        }

        $container->register(RouteLoader::class)
            ->setArguments(['$enabled' => '%pumukitoai.enable%', '$env' => '%kernel.environment%'])
            ->addTag('routing.loader')
        ;

        if (!$config['enable']) {
            return;
        }

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_oai.yaml');
    }
}
