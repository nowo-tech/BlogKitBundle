<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Nowo\BlogKitBundle\Doctrine\BlogKitEntityManagerFactory;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Doctrine\BlogUserReferenceMetadataListener;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\Enum\BlogObjectAccessStrategy;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Port\Ports;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Nowo\BlogKitBundle\Security\BlogProtectionConfig;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Security\Captcha\PublicBlogCommentCaptchaTypeExtension;
use Nowo\BlogKitBundle\Security\Captcha\StreamCaptchaHttpClient;
use Nowo\BlogKitBundle\Security\ConfigurableBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\OwnerBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\Worker\BlogKitOwnEntityManagerSubscriber;
use Nowo\BlogKitBundle\Worker\UnitOfWorkBoundaries;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Loads BlogKit services and publishes configuration parameters.
 */
final class NowoBlogKitExtension extends Extension implements PrependExtensionInterface
{
    /** Since 1.9.0: container parameter holding `doctrine.entity_manager` (null: the manager that maps the entities). */
    public const string ENTITY_MANAGER_PARAMETER = 'nowo_blog_kit.doctrine.entity_manager';

    /** Since 1.9.0: the entity manager every bundle service reads and writes through. */
    public const string ENTITY_MANAGER_SERVICE = 'nowo_blog_kit.entity_manager';

    /**
     * Since 1.9.0: one of these parameters is set, and enables the matching mapping pass of the bundle class:
     * attribute / xml mapping, on the default manager or on `doctrine.entity_manager`.
     */
    public const string MAPPING_ATTRIBUTE_DEFAULT = 'nowo_blog_kit.doctrine.mapping.attribute_default';

    public const string MAPPING_XML_DEFAULT = 'nowo_blog_kit.doctrine.mapping.xml_default';

    public const string MAPPING_ATTRIBUTE_DEDICATED = 'nowo_blog_kit.doctrine.mapping.attribute_dedicated';

    public const string MAPPING_XML_DEDICATED = 'nowo_blog_kit.doctrine.mapping.xml_dedicated';

    /** Since 1.9.0: the configured host port services the ports pass checks (config key => [service id, 1.x interface, twin]). */
    public const string PORTS_PARAMETER = 'nowo_blog_kit.ports';

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        $this->prependDoctrineResolveTarget($container);
        $this->prependDoctrineUserReference($container);
        $this->prependFrameworkDefaults($container);
        $this->prependUiKitDefaults($container);
    }

    /**
     * Since 1.9.0 (`doctrine.user_reference: id`): registers the DBAL type of the user id columns, and (with
     * `doctrine.entity_manager`) turns off DoctrineBundle 3.4 `auto_discover_entities` on the bundle's manager unless
     * the host set it (LegalKit 1.13.1 pattern): that manager is declared without mappings, so its default driver would
     * otherwise claim every entity class.
     */
    private function prependDoctrineUserReference(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $config = $this->processConfiguration(new BundleConfiguration(), $container->getExtensionConfig(BundleConfiguration::ALIAS));
        if ($config['doctrine']['user_reference'] === BlogUsers::ID) {
            $container->prependExtensionConfig('doctrine', ['dbal' => ['types' => [BlogUserRefType::NAME => BlogUserRefType::class]]]);
        }

        $manager = $config['doctrine']['entity_manager'];
        if (!is_string($manager)
            || !$this->hostHasKey($container, 'doctrine', ['orm', 'entity_managers', $manager])
            || $this->hostHasKey($container, 'doctrine', ['orm', 'entity_managers', $manager, 'auto_discover_entities'])
            || !self::supportsEntityAutoDiscovery()) {
            return;
        }

        // Appended, not prepended: a prepended entry would make this manager the first one, i.e. the default one.
        $container->loadFromExtension('doctrine', ['orm' => ['entity_managers' => [$manager => ['auto_discover_entities' => false]]]]);
    }

    /**
     * Whether the installed DoctrineBundle has the per-manager `auto_discover_entities` option (3.4+).
     */
    public static function supportsEntityAutoDiscovery(): bool
    {
        $tree     = new DoctrineConfiguration(false)->getConfigTreeBuilder()->buildTree();
        $orm      = $tree instanceof ArrayNode ? ($tree->getChildren()['orm'] ?? null) : null;
        $managers = $orm instanceof ArrayNode ? ($orm->getChildren()['entity_managers'] ?? null) : null;
        $manager  = $managers instanceof PrototypedArrayNode ? $managers->getPrototype() : null;

        return $manager instanceof ArrayNode && isset($manager->getChildren()['auto_discover_entities']);
    }

    /**
     * Whether a host configuration of `$extension` sets the nested key `$path`.
     *
     * @param list<string> $path
     */
    private function hostHasKey(ContainerBuilder $container, string $extension, array $path): bool
    {
        foreach ($container->getExtensionConfig($extension) as $config) {
            $node = $config;
            foreach ($path as $key) {
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    continue 2;
                }

                $node = $node[$key];
            }

            return true;
        }

        return false;
    }

    private function prependFrameworkDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }

        $framework = [
            'assets' => [
                'packages' => [
                    BundleConfiguration::ALIAS => [
                        'base_path' => '/bundles/nowoblogkit',
                    ],
                ],
            ],
        ];

        $translationsPath = __DIR__ . '/../Resources/translations';
        if (is_dir($translationsPath)) {
            $framework['translator'] = [
                'paths'     => [$translationsPath],
                'fallbacks' => ['en'],
            ];
        }

        $container->prependExtensionConfig('framework', $framework);
    }

    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework      = false;
        $hostHasIconSet           = false;
        $hostHasRowActionsDisplay = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
            if (array_key_exists('row_actions_display', $cfg)) {
                $hostHasRowActionsDisplay = true;
            }
        }

        $config = $this->processConfiguration(new BundleConfiguration(), $container->getExtensionConfig(BundleConfiguration::ALIAS));
        /** @var array<string, mixed> $webUi */
        $webUi    = $config['web_ui'];
        $defaults = [];

        // Deprecated per-bundle keys (REQ-UI-003: the CSS stack is inherited from nowo_ui_kit): copied to UiKit only
        // when the host set them and UiKit does not have its own value.
        $copy = [
            'css_framework'       => !$hostHasCssFramework,
            'icon_set'            => !$hostHasIconSet,
            'row_actions_display' => !$hostHasRowActionsDisplay,
        ];
        foreach ($copy as $key => $missing) {
            $value = $webUi[$key] ?? null;
            if ($missing && is_string($value) && $value !== '') {
                $defaults[$key] = $value;
            }
        }

        if ($defaults === []) {
            return;
        }

        $container->prependExtensionConfig('nowo_ui_kit', $defaults);
    }

    private function prependDoctrineResolveTarget(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $configs   = $container->getExtensionConfig(BundleConfiguration::ALIAS);
        $config    = $this->processConfiguration(new BundleConfiguration(), $configs);
        $userClass = $config['user_class'] ?? null;

        // Since 1.9.0: the id mode has no relation to the user class (it need not be a Doctrine entity).
        if (!is_string($userClass) || $userClass === '' || $config['doctrine']['user_reference'] === BlogUsers::ID) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'resolve_target_entities' => [
                    BlogUserInterface::class => $userClass,
                ],
            ],
        ]);
    }

    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'type_map' => [
                'entity' => EntityType::class,
            ],
        ]);

        $hostHasProfile       = false;
        $hostHasFilterProfile = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('blog_kit', $profiles)) {
                $hostHasProfile = true;
            }
            if (is_array($profiles) && array_key_exists('filter', $profiles)) {
                $hostHasFilterProfile = true;
            }
        }

        $profiles = [];
        if (!$hostHasProfile) {
            $profiles['blog_kit'] = [
                'alias'              => 'blog_kit',
                'translation_domain' => 'NowoBlogKitBundle',
                'defaults'           => [
                    'attr'     => ['class' => 'nowo-ui-input'],
                    'row_attr' => [],
                ],
            ];
        }
        if (!$hostHasFilterProfile) {
            $profiles['filter'] = [
                'alias'              => 'filter',
                'translation_domain' => 'NowoBlogKitBundle',
                'auto_placeholder'   => true,
                'auto_help'          => true,
                'defaults'           => [
                    'label'    => false,
                    'required' => false,
                    'attr'     => [],
                    'row_attr' => [],
                ],
            ];
        }

        if ($profiles === []) {
            return;
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => $profiles,
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new BundleConfiguration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setParameter('nowo_blog_kit.user_class', $config['user_class']);
        $container->setParameter('nowo_blog_kit.default_locale', $config['default_locale']);
        $container->setParameter('nowo_blog_kit.locales', $config['locales']);
        $container->setParameter('nowo_blog_kit.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter('nowo_blog_kit.doctrine.connection', $config['doctrine']['connection']);
        $container->setParameter('nowo_blog_kit.security.access_roles', $config['security']['access_roles']);
        $container->setParameter('nowo_blog_kit.security.roles', $config['security']['roles']);
        $container->setParameter('nowo_blog_kit.security.manage_roles', $config['security']['roles']['manage']);
        $container->setParameter('nowo_blog_kit.security.moderate_roles', $config['security']['roles']['moderate']);
        $container->setParameter('nowo_blog_kit.security.configure_roles', $config['security']['roles']['configure']);
        $container->setParameter('nowo_blog_kit.security.access_checker', $config['security']['access_checker']);
        $container->setParameter('nowo_blog_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_blog_kit.security.object_access.strategy', (string) $config['security']['object_access']['strategy']);
        $container->setParameter('nowo_blog_kit.security.object_access.service', $config['security']['object_access']['service']);
        $container->setParameter('nowo_blog_kit.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_blog_kit.web_ui.public_layout_template', $config['web_ui']['public_layout_template']);
        $container->setParameter('nowo_blog_kit.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_blog_kit.web_ui.css_framework', $config['web_ui']['css_framework']);
        $container->setParameter('nowo_blog_kit.web_ui.icon_set', $config['web_ui']['icon_set']);
        $container->setParameter('nowo_blog_kit.web_ui.row_actions_display', $config['web_ui']['row_actions_display']);
        $container->setParameter('nowo_blog_kit.web_ui.page_size', $config['web_ui']['page_size']);
        $container->setParameter('nowo_blog_kit.web_ui.privacy_url', $config['web_ui']['privacy_url']);
        $container->setParameter('nowo_blog_kit.comments.staff_name', (string) $config['comments']['staff_name']);
        $container->setParameter('nowo_blog_kit.listing.mode', (string) $config['listing']['mode']);
        $container->setParameter('nowo_blog_kit.listing.masonry.strategy', (string) $config['listing']['masonry']['strategy']);
        $container->setParameter('nowo_blog_kit.listing.masonry.columns_mobile', (int) $config['listing']['masonry']['columns_mobile']);
        $container->setParameter('nowo_blog_kit.listing.masonry.columns_tablet', (int) $config['listing']['masonry']['columns_tablet']);
        $container->setParameter('nowo_blog_kit.listing.masonry.columns_desktop', (int) $config['listing']['masonry']['columns_desktop']);

        $container->getDefinition(BlogLocales::class)
            ->setArgument('$defaultLocale', $config['default_locale'])
            ->setArgument('$locales', $config['locales']);

        if (
            !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoBlogKitBundle admin UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $this->registerPersistence($container, $config);
        $this->registerAccessChecker($container, $config['security']);
        $this->registerObjectAccess($container, $config['security']);
        $this->registerBlogProtection($container, $config);

        $tablePrefix = (string) $config['doctrine']['table_prefix'];
        if ($tablePrefix !== '') {
            $definition = new Definition(TablePrefixListener::class, [$tablePrefix]);
            $definition->addTag('doctrine.event_listener', ['event' => Events::loadClassMetadata]);
            $container->setDefinition(TablePrefixListener::class, $definition);
        }
    }

    public function getAlias(): string
    {
        return BundleConfiguration::ALIAS;
    }

    /**
     * @param array{
     *     access_checker: ?string,
     *     access_roles: list<string>,
     *     roles: array{manage: list<string>, moderate: list<string>, configure: list<string>},
     *     allow_unauthenticated: bool,
     *     object_access: array{strategy: string, service: ?string}
     * } $security
     */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        if ($security['allow_unauthenticated']) {
            $id = 'nowo_blog_kit.access_checker.allow_all';
            $container->setDefinition($id, new Definition(AllowAllBlogKitAccessChecker::class));
            $container->setAlias(BlogKitAccessCheckerInterface::class, $id);

            return;
        }

        $custom = $security['access_checker'] ?? null;
        if (is_string($custom) && $custom !== '') {
            $container->setAlias(BlogKitAccessCheckerInterface::class, $custom);

            return;
        }

        if ($security['access_roles'] === [] && $security['roles']['manage'] === [] && $security['roles']['moderate'] === [] && $security['roles']['configure'] === []) {
            throw new LogicException('NowoBlogKitBundle: security.access_roles and every security.roles.* list are empty, so nobody could use the admin UI. Configure at least one role (an empty list denies), or set security.allow_unauthenticated for a trusted demo.');
        }

        $id         = 'nowo_blog_kit.access_checker.default';
        $definition = new Definition(ConfigurableBlogKitAccessChecker::class);
        $definition->setArgument('$accessRoles', $security['access_roles']);
        $definition->setArgument('$manageRoles', $security['roles']['manage']);
        $definition->setArgument('$moderateRoles', $security['roles']['moderate']);
        $definition->setArgument('$configureRoles', $security['roles']['configure']);
        $definition->setArgument('$authorizationChecker', new Reference('security.authorization_checker'));
        $container->setDefinition($id, $definition);
        $container->setAlias(BlogKitAccessCheckerInterface::class, $id);
    }

    /**
     * @param array{
     *     allow_unauthenticated: bool,
     *     object_access: array{strategy: string, service: ?string}
     * } $security
     */
    private function registerObjectAccess(ContainerBuilder $container, array $security): void
    {
        $strategy = BlogObjectAccessStrategy::from((string) $security['object_access']['strategy']);

        if ($security['allow_unauthenticated'] || $strategy === BlogObjectAccessStrategy::None) {
            $id = 'nowo_blog_kit.object_access.allow_all';
            $container->setDefinition($id, new Definition(AllowAllBlogKitResourceAccessChecker::class));
            $container->setAlias(BlogKitResourceAccessCheckerInterface::class, $id);

            return;
        }

        if ($strategy === BlogObjectAccessStrategy::Service) {
            $custom = $this->optionalServiceId($security['object_access']['service'] ?? null);
            if ($custom === null) {
                throw new LogicException('nowo_blog_kit.security.object_access.service is required when strategy is service.');
            }

            // Since 1.9.0: the 1.x interface or its twin (BlogKitResourcePolicyInterface), see Port\Ports.
            $container->register('nowo_blog_kit.object_access.service', BlogKitResourceAccessCheckerInterface::class)
                ->setFactory([Ports::class, 'resourceAccess'])
                ->setArguments([new Reference($custom)]);
            $container->setAlias(BlogKitResourceAccessCheckerInterface::class, 'nowo_blog_kit.object_access.service');
            $this->addPort($container, 'security.object_access.service', $custom, BlogKitResourceAccessCheckerInterface::class, BlogKitResourcePolicyInterface::class);

            return;
        }

        $id         = 'nowo_blog_kit.object_access.owner';
        $definition = new Definition(OwnerBlogKitResourceAccessChecker::class);
        $definition->setArgument('$accessChecker', new Reference(BlogKitAccessCheckerInterface::class));
        $definition->setArgument(
            '$tokenStorage',
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );
        $container->setDefinition($id, $definition);
        $container->setAlias(BlogKitResourceAccessCheckerInterface::class, $id);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerBlogProtection(ContainerBuilder $container, array $config): void
    {
        /** @var array<string, mixed> $comments */
        $comments = $config['comments'];
        /** @var array<string, mixed> $rateLimit */
        $rateLimit = $comments['rate_limit'];
        /** @var array<string, mixed> $captcha */
        $captcha = $comments['captcha'];
        /** @var array<string, mixed> $html */
        $html = $config['html']['sanitize'];

        // A `service` strategy without its service id used to fall back to a no-op (SEC-004 L5): fail at compile time.
        foreach (['comments.rate_limit' => $rateLimit, 'comments.captcha' => $captcha, 'html.sanitize' => $html] as $path => $node) {
            if ($node['strategy'] === 'service' && $this->optionalServiceId($node['service'] ?? null) === null) {
                throw new LogicException(sprintf('nowo_blog_kit.%s.service is required when strategy is service.', $path));
            }
        }

        $container->register(StreamCaptchaHttpClient::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([null, (float) $captcha['timeout_seconds'], new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $container->setAlias(CaptchaHttpClientInterface::class, StreamCaptchaHttpClient::class);

        $container->register(BlogProtectionConfig::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                CommentRateLimitStrategy::from((string) $rateLimit['strategy']),
                (int) $rateLimit['limit'],
                (int) $rateLimit['interval_seconds'],
                $this->optionalServiceId($rateLimit['service'] ?? null),
                CommentCaptchaStrategy::from((string) $captcha['strategy']),
                (string) $captcha['site_key'],
                (string) $captcha['secret_key'],
                (float) $captcha['min_score'],
                (string) $captcha['honeypot_field'],
                $this->optionalServiceId($captcha['service'] ?? null),
                HtmlSanitizeStrategy::from((string) $html['strategy']),
                $this->optionalServiceId($html['service'] ?? null),
            ]);

        $customRateLimiter = $this->optionalServiceId($rateLimit['service'] ?? null);
        $customCaptcha     = $this->optionalServiceId($captcha['service'] ?? null);
        $customSanitizer   = $this->optionalServiceId($html['service'] ?? null);

        // Since 1.9.0: each port takes the 1.x interface or its twin (Port\Ports picks the gate).
        if ($customRateLimiter !== null) {
            $container->register('nowo_blog_kit.comments.rate_limit.service', BlogCommentRateLimiterInterface::class)
                ->setFactory([Ports::class, 'rateLimiter'])
                ->setArguments([new Reference($customRateLimiter)]);
            $this->addPort($container, 'comments.rate_limit.service', $customRateLimiter, BlogCommentRateLimiterInterface::class, BlogCommentRateLimitPolicyInterface::class);
        }

        if ($customCaptcha !== null) {
            $container->register('nowo_blog_kit.comments.captcha.service', BlogCommentCaptchaStrategyInterface::class)
                ->setFactory([Ports::class, 'captcha'])
                ->setArguments([new Reference($customCaptcha), new Reference('request_stack')]);
            $this->addPort($container, 'comments.captcha.service', $customCaptcha, BlogCommentCaptchaStrategyInterface::class, BlogCommentCaptchaVerifierInterface::class);
        }

        $container->register(BlogProtection::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference(BlogProtectionConfig::class),
                new Reference(BlogSettingsProvider::class),
                new Reference(CaptchaHttpClientInterface::class),
                new Reference('request_stack'),
                new Reference('cache.app', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $customRateLimiter !== null ? new Reference('nowo_blog_kit.comments.rate_limit.service') : null,
                $customCaptcha !== null ? new Reference('nowo_blog_kit.comments.captcha.service') : null,
                $customSanitizer !== null ? new Reference($customSanitizer) : null,
            ]);

        $container->register(PublicBlogCommentCaptchaTypeExtension::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([new Reference(BlogProtection::class)])
            ->addTag('form.type_extension', ['extended_type' => PublicBlogCommentType::class]);
    }

    /**
     * Since 1.9.0: records a configured host port for {@see Compiler\BlogKitPortsPass}.
     */
    private function addPort(ContainerBuilder $container, string $key, string $serviceId, string $legacy, string $twin): void
    {
        /** @var array<string, array{0: string, 1: string, 2: string}> $ports */
        $ports       = $container->hasParameter(self::PORTS_PARAMETER) ? $container->getParameter(self::PORTS_PARAMETER) : [];
        $ports[$key] = [$serviceId, $legacy, $twin];
        $container->setParameter(self::PORTS_PARAMETER, $ports);
    }

    /**
     * Since 1.9.0 (REQ-ARCH-001 rules 2 and 6): user references, mapping type and the entity manager of the bundle.
     *
     * @param array<string, mixed> $config
     */
    private function registerPersistence(ContainerBuilder $container, array $config): void
    {
        /** @var array{entity_manager: ?string, mapping_type: string, user_reference: string} $doctrine */
        $doctrine      = $config['doctrine'];
        $entityManager = $doctrine['entity_manager'];
        $userReference = $doctrine['user_reference'];
        $dedicated     = $entityManager !== null;

        if ($dedicated && $userReference !== BlogUsers::ID) {
            throw new LogicException(sprintf('nowo_blog_kit.doctrine.entity_manager "%s" needs nowo_blog_kit.doctrine.user_reference: id: the default "entity" mode is a relation to your user entity, which the bundle\'s own entity manager does not map.', $entityManager));
        }

        $container->setParameter(self::ENTITY_MANAGER_PARAMETER, $entityManager);
        $container->setParameter('nowo_blog_kit.doctrine.mapping_type', $doctrine['mapping_type']);
        $container->setParameter('nowo_blog_kit.doctrine.user_reference', $userReference);
        $container->setParameter(match (true) {
            $dedicated && $doctrine['mapping_type'] === 'xml' => self::MAPPING_XML_DEDICATED,
            $dedicated                                        => self::MAPPING_ATTRIBUTE_DEDICATED,
            $doctrine['mapping_type'] === 'xml'               => self::MAPPING_XML_DEFAULT,
            default                                           => self::MAPPING_ATTRIBUTE_DEFAULT,
        }, true);

        $directory = $this->optionalServiceId($config['user_directory'] ?? null);
        $container->register(BlogUsers::class, BlogUsers::class)
            ->setArguments([
                $userReference,
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $directory !== null ? new Reference($directory) : null,
            ]);

        if ($userReference === BlogUsers::ID) {
            $container->register(BlogUserReferenceMetadataListener::class, BlogUserReferenceMetadataListener::class)
                ->setArguments([new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
                ->addTag('doctrine.event_listener', ['event' => Events::loadClassMetadata]);
            $container->register(BlogUserBlameListener::class, BlogUserBlameListener::class)
                ->setArguments([new Reference(BlogUsers::class)])
                ->addTag('doctrine.event_listener', ['event' => Events::onFlush]);
        }

        if (!$dedicated) {
            $container->register(self::ENTITY_MANAGER_SERVICE, EntityManagerInterface::class)
                ->setFactory([BlogKitEntityManagerFactory::class, 'create'])
                ->setArguments([new Reference('doctrine')]);

            return;
        }

        $managerId = sprintf('doctrine.orm.%s_entity_manager', $entityManager);
        $container->setAlias(self::ENTITY_MANAGER_SERVICE, $managerId);
        // Only a manager the unit of work already built: a boundary never builds one (REQ-RUNTIME-002).
        $container->register(BlogKitOwnEntityManagerSubscriber::class, BlogKitOwnEntityManagerSubscriber::class)
            ->setArguments([
                new Reference(UnitOfWorkBoundaries::class),
                new IteratorArgument([new Reference($managerId, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]),
                new Reference('doctrine'),
                $entityManager,
            ])
            ->addTag('kernel.event_subscriber');
    }

    private function optionalServiceId(mixed $serviceId): ?string
    {
        if (!is_string($serviceId) || $serviceId === '') {
            return null;
        }

        return $serviceId;
    }

    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }
}
