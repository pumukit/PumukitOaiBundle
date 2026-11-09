<?php

declare(strict_types=1);

namespace Pumukit\OaiBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('pumukit_oai');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
            ->booleanNode('enable')
            ->defaultTrue()
            ->info('If false, /oai.xml gives 404 because no route is loaded, and nothing else of the bundle is loaded')
            ->end()
            ->booleanNode('list_only_published_objects')
            ->defaultTrue()
            ->info('List only multimedia objects in published status')
            ->end()
            ->scalarNode('pub_channel_tag')
            ->defaultValue('PUCHWEBTV')
            ->cannotBeEmpty()
            ->info('Publication channel the listed multimedia objects must have')
            ->end()
            ->scalarNode('display_track_tag')
            ->defaultValue('display')
            ->cannotBeEmpty()
            ->info('Tag of the visible media a listed multimedia object must have')
            ->end()
            ->integerNode('page_size')
            ->defaultValue(100)
            ->min(1)
            ->max(1000)
            ->info('Records or sets of each page of the list verbs')
            ->end()
            ->booleanNode('use_dc_thumbnail')
            ->defaultTrue()
            ->info('Use special tag dc:thumbnail to list the first object thumbnail (deprecated and non standard)')
            ->end()
            ->scalarNode('video_dc_type')
            ->defaultValue('Moving Image')
            ->info('DublinCore type for video and external contents. See http://dublincore.org/documents/dcmi-type-vocabulary/#H7')
            ->end()
            ->scalarNode('audio_dc_type')
            ->defaultValue('Sound')
            ->info('DublinCore type for audio contents. See http://dublincore.org/documents/dcmi-type-vocabulary/#H7')
            ->end()
            ->scalarNode('doc_dc_type')
            ->defaultValue('Text')
            ->info('DublinCore type for document contents. See http://dublincore.org/documents/dcmi-type-vocabulary/#H7')
            ->end()
            ->scalarNode('image_dc_type')
            ->defaultValue('Image')
            ->info('DublinCore type for image contents. See http://dublincore.org/documents/dcmi-type-vocabulary/#H7')
            ->end()
            ->scalarNode('role_for_dc_creator')
            ->defaultValue('actor')
            ->info('PuMuKIT role used to get the dc:creator. Null to use all the roles')
            ->end()
            ->booleanNode('use_license_as_dc_rights')
            ->defaultFalse()
            ->info('Use the object license as dc:rights')
            ->end()
            ->booleanNode('use_copyright_as_dc_publisher')
            ->defaultFalse()
            ->info('Use the object copyright as dc:publisher')
            ->end()
            ->enumNode('dc_subject_format')
            ->values(['all', 'code', 'title', 'e-ciencia'])
            ->defaultValue('title')
            ->info('Format used with dc:subject. All: "120000 - Mathematics", Code: "120000", Title: "Mathematics", E-ciencia: "12 Matemáticas"')
            ->end()
            ->enumNode('dc_identifier_url_mapping')
            ->values(['all', 'portal_and_track', 'portal', 'iframe', 'track'])
            ->defaultValue('portal')
            ->info('URL used with dc:identifier. URL to delivery track (.mp4), URL to iframe (.html), URL to portal (.html) or a combination')
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
