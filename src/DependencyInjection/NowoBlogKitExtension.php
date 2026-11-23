<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\ORM\Events;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\BlogKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Nowo\BlogKitBundle\Doctrine\BlogKitPrivateUserReferenceListener;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Doctrine\BlogUserReferenceMetadataListener;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Enum\BlogObjectAccessStrategy;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitWorkerStateSubscriber;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Port\Ports;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Nowo\BlogKitBundle\Security\BlogProtectionConfig;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Security\Captcha\PublicBlogCommentCaptchaTypeExtension;
use Nowo\BlogKitBundle\Security\Captcha\StreamCaptchaHttpClient;
use Nowo\BlogKitBundle\Security\ConfigurableBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\Internal\CommentCaptchaInterface;
use Nowo\BlogKitBundle\Security\Internal\CommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\Internal\ObjectAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\OwnerBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\Worker\BlogKitOwnEntityManagerSubscriber;
use Nowo\BlogKitBundle\Worker\UnitOfWorkBoundaries;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
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
use function str_starts_with;

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

    /** Since 1.12.0: container parameter holding `doctrine.private_entity_manager` (since 2.0: true or null, no effect; false is refused). */
    public const string PRIVATE_ENTITY_MANAGER_PARAMETER = 'nowo_blog_kit.doctrine.private_entity_manager';

    /**
     * Since 1.12.0: container parameter holding the name of your manager the private one is created on (empty outside
     * the private mode; resolved to `doctrine.default_entity_manager` by {@see Compiler\BlogKitPrivateEntityManagerPass}).
     */
    public const string PRIVATE_SOURCE_MANAGER_PARAMETER = 'nowo_blog_kit.private_entity_manager.source_manager';

    /** Since 1.12.0: the bundle's private entity manager (since 2.0 the only mode without `doctrine.entity_manager`). */
    public const string PRIVATE_ENTITY_MANAGER_SERVICE = 'nowo_blog_kit.private_entity_manager';

    /** Since 1.12.0: the registry the bundle's services get with the private manager (blog entities → the private manager). */
    public const string PRIVATE_REGISTRY_SERVICE = 'nowo_blog_kit.private_manager_registry';

    /** Since 1.12.0: tells the private manager how the connection's transactions end (tagged by the compiler pass). */
    public const string PRIVATE_MIDDLEWARE_SERVICE = 'nowo_blog_kit.private_entity_manager_middleware';

    /** Since 1.12.0: savepoints of the bundle itself (the settings singleton insert): their rollback keeps the private manager. */
    public const string OWN_SAVEPOINT_PREFIX = 'blog_kit_';

    /** Since 2.0: the bundle named by the private entity manager's warnings (moved from the removed host-flush reporter). */
    public const string BUNDLE_NAME = 'NowoBlogKitBundle';

    /** Since 2.0: the bundle's entity namespace, the only classes the private entity manager writes. */
    public const array OWN_NAMESPACES = ['Nowo\\BlogKitBundle\\Entity'];

    /** Since 1.9.0: the configured host port services the ports pass checks (since 2.0: config key => [service id, interface]). */
    public const string PORTS_PARAMETER = 'nowo_blog_kit.ports';

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        $this->prependDoctrineResolveTarget($container);
        $this->prependDoctrineUserReference($container);
        $this->prependFrameworkDefaults($container);
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
        $container->setParameter('nowo_blog_kit.web_ui.page_size', $config['web_ui']['page_size']);
        $container->setParameter('nowo_blog_kit.web_ui.privacy_url', $config['web_ui']['privacy_url']);
        $container->setParameter('nowo_blog_kit.comments.staff_name', (string) $config['comments']['staff_name']);
        $container->setParameter(ConflictResponsePass::PARAMETER, $config['conflict_response']);
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
            $container->setAlias(ObjectAccessCheckerInterface::class, $id);

            return;
        }

        if ($strategy === BlogObjectAccessStrategy::Service) {
            $custom = $this->optionalServiceId($security['object_access']['service'] ?? null);
            if ($custom === null) {
                throw new LogicException('nowo_blog_kit.security.object_access.service is required when strategy is service.');
            }

            // Since 2.0: a BlogKitResourcePolicyInterface only (the 1.x interface was removed), see Port\Ports.
            $container->register('nowo_blog_kit.object_access.service', ObjectAccessCheckerInterface::class)
                ->setFactory([Ports::class, 'resourceAccess'])
                ->setArguments([new Reference($custom)]);
            $container->setAlias(ObjectAccessCheckerInterface::class, 'nowo_blog_kit.object_access.service');
            $this->addPort($container, 'security.object_access.service', $custom, BlogKitResourcePolicyInterface::class);

            return;
        }

        $id         = 'nowo_blog_kit.object_access.owner';
        $definition = new Definition(OwnerBlogKitResourceAccessChecker::class);
        $definition->setArgument('$accessChecker', new Reference(BlogKitAccessCheckerInterface::class));
        $definition->setArgument(
            '$tokenStorage',
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );
        $definition->setArgument('$users', new Reference(BlogUsers::class));
        $container->setDefinition($id, $definition);
        $container->setAlias(ObjectAccessCheckerInterface::class, $id);
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

        // Since 2.0: each port takes only its 1.9 interface (the 1.x ones were removed); Port\Ports builds the gate.
        if ($customRateLimiter !== null) {
            $container->register('nowo_blog_kit.comments.rate_limit.service', CommentRateLimiterInterface::class)
                ->setFactory([Ports::class, 'rateLimiter'])
                ->setArguments([new Reference($customRateLimiter)]);
            $this->addPort($container, 'comments.rate_limit.service', $customRateLimiter, BlogCommentRateLimitPolicyInterface::class);
        }

        if ($customCaptcha !== null) {
            $container->register('nowo_blog_kit.comments.captcha.service', CommentCaptchaInterface::class)
                ->setFactory([Ports::class, 'captcha'])
                ->setArguments([new Reference($customCaptcha), new Reference('request_stack')]);
            $this->addPort($container, 'comments.captcha.service', $customCaptcha, BlogCommentCaptchaVerifierInterface::class);
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
     * Since 1.9.0: records a configured host port for {@see Compiler\BlogKitPortsPass} (since 2.0: its only interface).
     */
    private function addPort(ContainerBuilder $container, string $key, string $serviceId, string $interface): void
    {
        /** @var array<string, array{0: string, 1: string}> $ports */
        $ports       = $container->hasParameter(self::PORTS_PARAMETER) ? $container->getParameter(self::PORTS_PARAMETER) : [];
        $ports[$key] = [$serviceId, $interface];
        $container->setParameter(self::PORTS_PARAMETER, $ports);
    }

    /**
     * Since 1.9.0 (REQ-ARCH-001 rules 2 and 6): user references, mapping type and the entity manager of the bundle.
     *
     * @param array<string, mixed> $config
     */
    private function registerPersistence(ContainerBuilder $container, array $config): void
    {
        /** @var array{entity_manager: ?string, private_entity_manager: ?bool, mapping_type: string, user_reference: string} $doctrine */
        $doctrine      = $config['doctrine'];
        $entityManager = $doctrine['entity_manager'];
        $private       = $doctrine['private_entity_manager'] ?? null;
        $userReference = $doctrine['user_reference'];
        $dedicated     = $entityManager !== null;

        if ($dedicated && $private === true) {
            throw new LogicException(sprintf('nowo_blog_kit.doctrine.private_entity_manager: true cannot be combined with nowo_blog_kit.doctrine.entity_manager "%s": the dedicated manager already keeps the blog writes apart from yours. Remove private_entity_manager (deprecated since 2.0, no effect).', $entityManager));
        }

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
            // Since 1.12.0 (audit XD-3): a refused flush closes only the bundle's own manager, never one of yours.
            $container->register(BlogUserBlameListener::class, BlogUserBlameListener::class)
                ->setArguments([new Reference(BlogUsers::class), new Reference('doctrine'), $entityManager])
                ->addTag('doctrine.event_listener', ['event' => Events::onFlush]);
        }

        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_PARAMETER, $private);
        $container->setParameter(self::PRIVATE_SOURCE_MANAGER_PARAMETER, '');

        // Only services the unit of work already built: a boundary never builds one (REQ-RUNTIME-002).
        $resettables = [];
        $managers    = [];
        $managerName = null;

        if ($dedicated) {
            $managerId   = sprintf('doctrine.orm.%s_entity_manager', $entityManager);
            $managerName = $entityManager;
            $managers[]  = new Reference($managerId, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
            $container->setAlias(self::ENTITY_MANAGER_SERVICE, $managerId);
        } else {
            // Since 2.0 (REQ-ARCH-001 rule 6): the only mode without a dedicated manager; your manager is never flushed.
            $this->registerPrivateEntityManager($container, $userReference);
            $resettables[] = new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        $container->register(BlogKitOwnEntityManagerSubscriber::class, BlogKitOwnEntityManagerSubscriber::class)
            ->setArguments([
                new Reference(UnitOfWorkBoundaries::class),
                new IteratorArgument($managers),
                new Reference('doctrine'),
                $managerName,
                new IteratorArgument($resettables),
            ])
            ->addTag('kernel.event_subscriber');
    }

    /**
     * Since 1.12.0 (opt-in), the only mode without `doctrine.entity_manager` since 2.0 (REQ-ARCH-001 rule 6): the bundle's repositories, its entity
     * manager service (`nowo_blog_kit.entity_manager`, which the controllers and services get), its transaction runner
     * and its worker-state subscriber work through transaction-doctrine's {@see PrivateEntityManager}, created on the
     * connection, configuration and event manager of your default entity manager. Same tables, no host configuration.
     * It is dropped at every outermost unit-of-work boundary ({@see BlogKitOwnEntityManagerSubscriber}; also by
     * transaction-doctrine's bundle through the {@see PrivateEntityManager::TAG} tag, and at `kernel.reset`) and replaced
     * when closed. Your managers are never flushed, cleared, closed or reset by the bundle.
     */
    private function registerPrivateEntityManager(ContainerBuilder $container, string $userReference): void
    {
        $source = '%' . self::PRIVATE_SOURCE_MANAGER_PARAMETER . '%';
        $container->register(self::PRIVATE_ENTITY_MANAGER_SERVICE, PrivateEntityManager::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference('doctrine'),
                $source,
                BlogArticle::class,
                self::BUNDLE_NAME,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag(PrivateEntityManager::TAG)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register(self::PRIVATE_REGISTRY_SERVICE, PrivateManagerRegistry::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference('doctrine'),
                new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE),
                self::OWN_NAMESPACES,
                $source,
            ]);
        // Tagged doctrine.middleware for the source manager's connection by BlogKitPrivateEntityManagerPass.
        $container->register(self::PRIVATE_MIDDLEWARE_SERVICE, PrivateEntityManagerMiddleware::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE), self::OWN_SAVEPOINT_PREFIX]);
        $container->setAlias(self::ENTITY_MANAGER_SERVICE, self::PRIVATE_ENTITY_MANAGER_SERVICE);

        // Service repositories (also served by your manager's getRepository(), e.g. to entity value resolvers and
        // EntityType), the runner and the worker-state subscriber resolve the blog entities to the private manager.
        $registry = new Reference(self::PRIVATE_REGISTRY_SERVICE);
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if (str_starts_with($class, 'Nowo\\BlogKitBundle\\Repository\\') && !$definition->isAbstract()) {
                $definition->setArgument('$registry', $registry);
            }
        }

        $container->getDefinition(BlogKitTransactionRunner::class)->setArgument('$registry', $registry);
        $container->getDefinition(BlogKitWorkerStateSubscriber::class)->setArgument('$managerRegistry', $registry);

        if ($userReference === BlogUsers::ENTITY) {
            // Your user entities assigned to blog rows (author, moderator) become references of the private manager.
            $container->register(BlogKitPrivateUserReferenceListener::class, BlogKitPrivateUserReferenceListener::class)
                ->setAutowired(false)
                ->setAutoconfigured(false)
                ->addTag('doctrine.event_listener', ['event' => Events::preFlush]);
        }
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
