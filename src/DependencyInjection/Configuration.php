<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection;

use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use Nowo\BlogKitBundle\Enum\BlogObjectAccessStrategy;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_key_exists;
use function is_string;
use function sprintf;

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
                    ->info('FQCN of the host user entity implementing BlogUserInterface (doctrine.user_reference: entity). Not needed with user_reference: id.')
                ->end()
                ->scalarNode('user_directory')
                    ->defaultNull()
                    ->info('Since 1.9.0: service id of a Nowo\\BlogKitBundle\\User\\BlogUserDirectoryInterface (labels of the user ids kept with doctrine.user_reference: id). Optional: without it the pages show the id.')
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
                        ->then(static fn (array $security): array => self::refuseRemovedKeys($security, self::REMOVED_SECURITY_KEYS, 'security.'))
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
                                    ->info('Service id implementing BlogKitResourcePolicyInterface (1.9+) or the deprecated BlogKitResourceAccessCheckerInterface when strategy=service.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static fn (array $webUi): array => self::refuseRemovedKeys($webUi, self::REMOVED_WEB_UI_KEYS, 'web_ui.'))
                    ->end()
                    ->children()
                        ->scalarNode('layout_template')
                            ->beforeNormalization()
                                ->ifInArray(self::LEGACY_DEFAULT_LAYOUTS)
                                ->then(static fn (): ?string => null)
                            ->end()
                            ->defaultNull()
                            ->info('Admin layout of the UiKit page scaffolds (REQ-UI-003); null = nowo_ui_kit.layouts.admin. It must render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...): the 1.x layout bridge was removed in 2.0.')
                        ->end()
                        ->scalarNode('public_layout_template')
                            ->beforeNormalization()
                                ->ifInArray(self::LEGACY_DEFAULT_LAYOUTS)
                                ->then(static fn (): ?string => null)
                            ->end()
                            ->defaultNull()
                            ->info('Layout of the public blog pages; null = nowo_ui_kit.layouts.guest. It must render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...): the 1.x layout bridge was removed in 2.0.')
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template overrides (page id => template), e.g. article_index: "admin/blog/articles.html.twig" extending the bundle page.')
                            ->useAttributeAsKey('page')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
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
                    ->validate()
                        ->ifTrue(static fn (array $doctrine): bool => ($doctrine['private_entity_manager'] ?? null) === false)
                        ->then(static function (array $doctrine): never {
                            throw new InvalidConfigurationException(self::privateEntityManagerFalseMessage(is_string($doctrine['entity_manager'] ?? null) ? $doctrine['entity_manager'] : null));
                        })
                    ->end()
                    ->children()
                        ->scalarNode('table_prefix')->defaultValue('')->end()
                        ->scalarNode('connection')->defaultValue('default')->end()
                        ->scalarNode('entity_manager')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                                ->thenInvalid('doctrine.entity_manager must be a non-empty entity manager name or null.')
                            ->end()
                            ->info('Since 1.9.0 (REQ-ARCH-001 rule 6, opt-in): name of a Doctrine entity manager dedicated to the blog entities (declared in doctrine.orm.entity_managers, not the default one). The bundle maps its entities there, flushes only that manager and clears it at every request / message / command boundary. Needs user_reference: id. Null (default): the bundle\'s private entity manager on your default manager (2.0: the bundle never flushes your manager).')
                        ->end()
                        ->booleanNode('private_entity_manager')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/blog-kit-bundle', '2.0', self::PRIVATE_ENTITY_MANAGER_DEPRECATION)
                            ->info('Deprecated since 2.0, removed in 3.0. Without doctrine.entity_manager the bundle always reads and writes its entities through a private entity manager of its own, created on the connection, mapping and event listeners of your default entity manager (same tables, nothing to configure), so a blog write never flushes your pending changes. true: accepted, no effect (still refused together with doctrine.entity_manager, as in 1.12). false: refused (the 1.x mode in which the bundle flushed your manager was removed in 2.0).')
                        ->end()
                        ->enumNode('mapping_type')
                            ->values(['attribute', 'xml'])
                            ->defaultValue('attribute')
                            ->info('Since 1.9.0 (REQ-ARCH-001 rule 2): attribute (default) or xml (Resources/config/doctrine-xml, same metadata) for the blog entities.')
                        ->end()
                        ->enumNode('user_reference')
                            ->values(['entity', 'id'])
                            ->defaultValue('entity')
                            ->info('Since 1.9.0 (REQ-ARCH-001 rule 2): entity (default) = a Doctrine relation to user_class (which implements BlogUserInterface), as in 1.8; id = the user\'s id in a string column (the host user class implements nothing of the bundle and need not be a Doctrine entity).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('conflict_response')
                    ->info('Since 1.10.0 (REQ-RUNTIME-003 rule 6): answer of a bundle route to a lock conflict (deadlock, lock wait timeout, serialization failure): 409 + Retry-After + CONFLICT_001 when nothing was committed, 409 + CONFLICT_002 without Retry-After when part of the request was. X-Conflict-Code carries the code.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('status')
                            ->values([null, 409, 503])
                            ->defaultNull()
                            ->info('409 or 503. Null (default): nowo_transaction_doctrine.conflict_response.status when NowoTransactionDoctrineBundle is registered, else 409.')
                        ->end()
                        ->booleanNode('error_page')
                            ->defaultFalse()
                            ->info('Render the answer with your error page (an HttpException with the status, X-Conflict-Code and, for CONFLICT_001, Retry-After) instead of the platform conflict answer (JSON with "code", or the "try again" page).')
                        ->end()
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
                                    ->info('Service id implementing BlogCommentRateLimitPolicyInterface (1.9+) or the deprecated BlogCommentRateLimiterInterface when strategy=service.')
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
                                    ->info('Service id implementing BlogCommentCaptchaVerifierInterface (1.9+) or the deprecated BlogCommentCaptchaStrategyInterface when strategy=service.')
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

    /** `security` keys removed in 2.0 (deprecated since 1.7) => what replaces them. */
    public const array REMOVED_SECURITY_KEYS = [
        'manage_roles'    => 'use "security.roles.manage"',
        'moderate_roles'  => 'use "security.roles.moderate"',
        'configure_roles' => 'use "security.roles.configure"',
    ];

    /** `web_ui` keys removed in 2.0 (deprecated since 1.7) => what replaces them. */
    public const array REMOVED_WEB_UI_KEYS = [
        'css_framework'       => 'set "nowo_ui_kit.css_framework"',
        'icon_set'            => 'set "nowo_ui_kit.icon_set"',
        'row_actions_display' => 'set "nowo_ui_kit.row_actions_display"',
    ];

    /** Since 2.0: deprecation of `doctrine.private_entity_manager` (any value accepted; the key is removed in 3.0). */
    public const string PRIVATE_ENTITY_MANAGER_DEPRECATION = 'The "%node%" option at "%path%" has no effect since 2.0 (without doctrine.entity_manager the private entity manager is the only mode): remove it. The key is removed in 3.0.';

    /** 1.x default layouts: mapped to null (UiKit layouts). */
    public const array LEGACY_DEFAULT_LAYOUTS = [
        '@NowoBlogKitBundle/admin/layout.html.twig',
        '@NowoBlogKitBundle/public/layout.html.twig',
    ];

    /**
     * Since 2.0: build error of `doctrine.private_entity_manager: false` (2.0 removed the 1.x mode it kept).
     */
    public static function privateEntityManagerFalseMessage(?string $entityManager): string
    {
        $message = 'nowo_blog_kit.doctrine.private_entity_manager: false is no longer supported: 2.0 removed the mode in which blog writes flush your entity manager. Remove the key.';
        $message .= $entityManager !== null && $entityManager !== ''
            ? sprintf(' doctrine.entity_manager "%s" stays: your dedicated manager keeps working as in 1.x.', $entityManager)
            : ' The bundle then writes through its private entity manager (same connection and tables), which never flushes your pending changes; or set doctrine.entity_manager to a dedicated manager.';

        return $message . ' See UPGRADING.md "To 2.0.0".';
    }

    /**
     * Since 2.0: fails on a key removed in 2.0, naming its replacement.
     *
     * @param array<mixed> $values
     * @param array<string, string> $removed key => what replaces it
     *
     * @return array<mixed>
     */
    private static function refuseRemovedKeys(array $values, array $removed, string $path): array
    {
        foreach ($removed as $key => $replacement) {
            if (array_key_exists($key, $values)) {
                throw new InvalidConfigurationException(sprintf('The option "nowo_blog_kit.%s%s" was removed in 2.0 (deprecated since 1.7): %s. See UPGRADING.md "To 2.0.0".', $path, $key, $replacement));
            }
        }

        return $values;
    }
}
