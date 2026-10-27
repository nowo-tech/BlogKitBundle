<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection;

use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use Nowo\BlogKitBundle\Enum\BlogObjectAccessStrategy;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\CssFramework;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Enum\IconSet;
use Nowo\BlogKitBundle\Enum\RowActionsDisplay;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function is_array;

/**
 * Validates and normalizes `nowo_blog_kit` configuration.
 */
final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'nowo_blog_kit';

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
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static fn (array $security): array => self::mapDeprecatedRoleKeys($security))
                    ->end()
                    ->children()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('Grants every admin capability when any listed role is held (REQ-UI-002).')
                        ->end()
                        ->arrayNode('roles')
                            ->addDefaultsIfNotSet()
                            ->info('Roles per admin action (REQ-UI-003 vocabulary), in addition to access_roles. An empty list grants nothing (only access_roles).')
                            ->children()
                                ->arrayNode('manage')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_EDITOR'])
                                    ->info('Articles and tags CRUD.')
                                ->end()
                                ->arrayNode('moderate')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_MODERATOR'])
                                    ->info('Comment moderation.')
                                ->end()
                                ->arrayNode('configure')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_ADMIN'])
                                    ->info('Blog settings.')
                                ->end()
                            ->end()
                        ->end()
                        ->variableNode('manage_roles')
                            ->defaultNull()
                            ->info('Deprecated since 1.7: use security.roles.manage.')
                            ->setDeprecated('nowo-tech/blog-kit-bundle', '1.7', 'The "%node%" option at "%path%" is deprecated, use "security.roles.manage" instead.')
                        ->end()
                        ->variableNode('moderate_roles')
                            ->defaultNull()
                            ->info('Deprecated since 1.7: use security.roles.moderate.')
                            ->setDeprecated('nowo-tech/blog-kit-bundle', '1.7', 'The "%node%" option at "%path%" is deprecated, use "security.roles.moderate" instead.')
                        ->end()
                        ->variableNode('configure_roles')
                            ->defaultNull()
                            ->info('Deprecated since 1.7: use security.roles.configure.')
                            ->setDeprecated('nowo-tech/blog-kit-bundle', '1.7', 'The "%node%" option at "%path%" is deprecated, use "security.roles.configure" instead.')
                        ->end()
                        ->scalarNode('access_checker')->defaultNull()->end()
                        ->booleanNode('allow_unauthenticated')->defaultFalse()->end()
                        ->arrayNode('object_access')
                            ->addDefaultsIfNotSet()
                            ->info('Object-level checks after roles (e.g. editors only manage their publications).')
                            ->children()
                                ->enumNode('strategy')
                                    ->values(BlogObjectAccessStrategy::values())
                                    ->defaultValue(BlogObjectAccessStrategy::None->value)
                                    ->info('none (roles only), owner (createdBy), or service (host checker).')
                                ->end()
                                ->scalarNode('service')
                                    ->defaultNull()
                                    ->info('Service id implementing BlogKitResourceAccessCheckerInterface when strategy=service.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout_template')
                            ->beforeNormalization()
                                ->ifInArray(self::LEGACY_DEFAULT_LAYOUTS)
                                ->then(static fn (): ?string => null)
                            ->end()
                            ->defaultNull()
                            ->info('Admin layout of the UiKit page scaffolds (REQ-UI-003); null = nowo_ui_kit.layouts.admin. A 1.x layout (no UiKit canonical blocks) is wrapped by a deprecated bridge.')
                        ->end()
                        ->scalarNode('public_layout_template')
                            ->beforeNormalization()
                                ->ifInArray(self::LEGACY_DEFAULT_LAYOUTS)
                                ->then(static fn (): ?string => null)
                            ->end()
                            ->defaultNull()
                            ->info('Layout of the public blog pages; null = nowo_ui_kit.layouts.guest. A 1.x layout (block body) is wrapped by a deprecated bridge.')
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template overrides (page id => template), e.g. article_index: "admin/blog/articles.html.twig" extending the bundle page.')
                            ->useAttributeAsKey('page')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->enumNode('css_framework')
                            ->values([...CssFramework::values(), null])
                            ->defaultNull()
                            ->info('Deprecated since 1.7: set nowo_ui_kit.css_framework. When set (and nowo_ui_kit.css_framework is not), it is copied to UiKit.')
                            ->setDeprecated('nowo-tech/blog-kit-bundle', '1.7', 'The "%node%" option at "%path%" is deprecated, set "nowo_ui_kit.css_framework" instead.')
                        ->end()
                        ->enumNode('icon_set')
                            ->values([...IconSet::values(), null])
                            ->defaultNull()
                            ->info('Deprecated since 1.7: set nowo_ui_kit.icon_set.')
                            ->setDeprecated('nowo-tech/blog-kit-bundle', '1.7', 'The "%node%" option at "%path%" is deprecated, set "nowo_ui_kit.icon_set" instead.')
                        ->end()
                        ->enumNode('row_actions_display')
                            ->values([...RowActionsDisplay::values(), null])
                            ->defaultNull()
                            ->info('Deprecated since 1.7: set nowo_ui_kit.row_actions_display.')
                            ->setDeprecated('nowo-tech/blog-kit-bundle', '1.7', 'The "%node%" option at "%path%" is deprecated, set "nowo_ui_kit.row_actions_display" instead.')
                        ->end()
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
                ->arrayNode('comments')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('staff_name')
                            ->defaultValue('Team')
                            ->cannotBeEmpty()
                            ->info('Public author name of staff replies when the user does not implement BlogStaffDisplayNameInterface (the login identifier is never shown).')
                        ->end()
                        ->arrayNode('rate_limit')
                            ->addDefaultsIfNotSet()
                            ->info('Public comment POST rate limiting. Requires cache.app except strategy none/service.')
                            ->children()
                                ->enumNode('strategy')
                                    ->values(CommentRateLimitStrategy::values())
                                    ->defaultValue(CommentRateLimitStrategy::FixedWindow->value)
                                ->end()
                                ->integerNode('limit')
                                    ->min(0)
                                    ->defaultValue(5)
                                    ->info('Max submissions per interval. 0 disables the limiter.')
                                ->end()
                                ->integerNode('interval_seconds')
                                    ->min(0)
                                    ->defaultValue(60)
                                ->end()
                                ->scalarNode('service')
                                    ->defaultNull()
                                    ->info('Service id implementing BlogCommentRateLimiterInterface when strategy=service.')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('captcha')
                            ->addDefaultsIfNotSet()
                            ->info('Public comment bot mitigation. Secrets stay in YAML, not BlogSettings.')
                            ->children()
                                ->enumNode('strategy')
                                    ->values(CommentCaptchaStrategy::values())
                                    ->defaultValue(CommentCaptchaStrategy::Honeypot->value)
                                ->end()
                                ->scalarNode('site_key')->defaultValue('')->end()
                                ->scalarNode('secret_key')->defaultValue('')->end()
                                ->floatNode('min_score')
                                    ->min(0)
                                    ->max(1)
                                    ->defaultValue(0.5)
                                    ->info('Minimum reCAPTCHA v3 score.')
                                ->end()
                                ->scalarNode('honeypot_field')->defaultValue('website')->end()
                                ->floatNode('timeout_seconds')
                                    ->min(0.1)
                                    ->max(60)
                                    ->defaultValue(5.0)
                                    ->info('Timeout of the remote verification call made by the default HTTP client.')
                                ->end()
                                ->scalarNode('service')
                                    ->defaultNull()
                                    ->info('Service id implementing BlogCommentCaptchaStrategyInterface when strategy=service.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('listing')
                    ->addDefaultsIfNotSet()
                    ->info('Public /blog index: numbered pagination or infinite scroll.')
                    ->children()
                        ->enumNode('mode')
                            ->values(BlogListingMode::values())
                            ->defaultValue(BlogListingMode::Paginated->value)
                            ->info('paginated (numbered pages) or infinite (IntersectionObserver + ?partial=1).')
                        ->end()
                        ->arrayNode('masonry')
                            ->addDefaultsIfNotSet()
                            ->info('Public index card layout. Admin inherit / 0 keeps these YAML values.')
                            ->children()
                                ->enumNode('strategy')
                                    ->values(BlogMasonryStrategy::values())
                                    ->defaultValue(BlogMasonryStrategy::Masonry->value)
                                    ->info('masonry (packed columns), grid (uniform rows), or list (single column).')
                                ->end()
                                ->integerNode('columns_mobile')
                                    ->min(1)
                                    ->max(2)
                                    ->defaultValue(1)
                                ->end()
                                ->integerNode('columns_tablet')
                                    ->min(1)
                                    ->max(2)
                                    ->defaultValue(2)
                                ->end()
                                ->integerNode('columns_desktop')
                                    ->min(1)
                                    ->max(3)
                                    ->defaultValue(2)
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('html')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('sanitize')
                            ->addDefaultsIfNotSet()
                            ->info('Sanitize article HTML on persist and public render. Default allowlist strips scripts; set none only for fully trusted editors.')
                            ->children()
                                ->enumNode('strategy')
                                    ->values(HtmlSanitizeStrategy::values())
                                    ->defaultValue(HtmlSanitizeStrategy::Allowlist->value)
                                ->end()
                                ->scalarNode('service')
                                    ->defaultNull()
                                    ->info('Service id implementing BlogHtmlSanitizerInterface when strategy=service.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /** 1.x default layouts: mapped to null (UiKit layouts). */
    public const array LEGACY_DEFAULT_LAYOUTS = [
        '@NowoBlogKitBundle/admin/layout.html.twig',
        '@NowoBlogKitBundle/public/layout.html.twig',
    ];

    /**
     * Copies the deprecated `security.*_roles` keys into `security.roles.*` when the canonical key is not set.
     *
     * @param array<mixed> $security
     *
     * @return array<mixed>
     */
    private static function mapDeprecatedRoleKeys(array $security): array
    {
        $roles = is_array($security['roles'] ?? null) ? $security['roles'] : [];
        foreach (['manage', 'moderate', 'configure'] as $action) {
            $legacy = $security[$action . '_roles'] ?? null;
            if ($legacy !== null && !array_key_exists($action, $roles)) {
                $roles[$action] = $legacy;
            }
        }
        if ($roles !== []) {
            $security['roles'] = $roles;
        }

        return $security;
    }
}
