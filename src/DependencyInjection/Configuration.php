<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection;

use Nowo\BlogKitBundle\Enum\CssFramework;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Validates and normalizes `nowo_blog_kit` configuration.
 */
final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'nowo_blog_kit';

    public const array CSS_FRAMEWORKS = [
        'bootstrap',
        'bootstrap4',
        'bootstrap5',
        'tabler',
        'tailwind',
        'foundation',
        'custom',
        'none',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('user_class')
                    ->defaultNull()
                    ->info('FQCN of the host user entity implementing BlogUserInterface.')
                ->end()
                ->scalarNode('default_locale')->defaultValue('es')->end()
                ->arrayNode('locales')
                    ->scalarPrototype()->end()
                    ->defaultValue(['es', 'en'])
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('manage_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_EDITOR'])
                        ->end()
                        ->arrayNode('moderate_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_MODERATOR'])
                        ->end()
                        ->arrayNode('configure_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')->defaultNull()->end()
                        ->booleanNode('allow_unauthenticated')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout_template')
                            ->defaultValue('@NowoBlogKitBundle/admin/layout.html.twig')
                        ->end()
                        ->scalarNode('public_layout_template')
                            ->defaultValue('@NowoBlogKitBundle/public/layout.html.twig')
                        ->end()
                        ->enumNode('css_framework')
                            ->values(CssFramework::values())
                            ->defaultValue(CssFramework::Tailwind->value)
                        ->end()
                        ->scalarNode('icon_set')->defaultValue('bootstrap-icons')->end()
                        ->integerNode('page_size')
                            ->min(1)
                            ->max(200)
                            ->defaultValue(20)
                        ->end()
                        ->scalarNode('privacy_url')->defaultValue('#')->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')->defaultValue('')->end()
                        ->scalarNode('connection')->defaultValue('default')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
