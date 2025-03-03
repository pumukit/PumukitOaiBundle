<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class PumukitOaiExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('pumukitoai.list_only_published_objects', $config['list_only_published_objects']);
        $container->setParameter('pumukitoai.pub_channel_tag', $config['pub_channel_tag']);
        $container->setParameter('pumukitoai.display_track_tag', $config['display_track_tag']);
        $container->setParameter('pumukitoai.use_dc_thumbnail', $config['use_dc_thumbnail']);
        $container->setParameter('pumukitoai.use_license_as_dc_rights', $config['use_license_as_dc_rights']);
        $container->setParameter('pumukitoai.use_copyright_as_dc_publisher', $config['use_copyright_as_dc_publisher']);
        $container->setParameter('pumukitoai.video_dc_type', $config['video_dc_type']);
        $container->setParameter('pumukitoai.audio_dc_type', $config['audio_dc_type']);
        $container->setParameter('pumukitoai.image_dc_type', $config['image_dc_type']);
        $container->setParameter('pumukitoai.doc_dc_type', $config['doc_dc_type']);
        $container->setParameter('pumukitoai.role_for_dc_creator', $config['role_for_dc_creator']);
        $container->setParameter('pumukitoai.dc_subject_format', $config['dc_subject_format']);
        $container->setParameter('pumukitoai.dc_identifier_url_mapping', $config['dc_identifier_url_mapping']);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_oai.yaml');
    }
}
