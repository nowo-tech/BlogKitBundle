<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection;

use Doctrine\ORM\Events;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Nowo\BlogKitBundle\Enum\BlogObjectAccessStrategy;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Nowo\BlogKitBundle\Security\BlogProtectionConfig;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Security\Captcha\PublicBlogCommentCaptchaTypeExtension;
use Nowo\BlogKitBundle\Security\Captcha\StreamCaptchaHttpClient;
use Nowo\BlogKitBundle\Security\ConfigurableBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\OwnerBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Config\FileLocator;
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

/**
 * Loads BlogKit services and publishes configuration parameters.
 */
final class NowoBlogKitExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        $this->prependDoctrineResolveTarget($container);
        $this->prependFrameworkDefaults($container);
        $this->prependUiKitDefaults($container);
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

        if (!$hostHasCssFramework) {
            $defaults['css_framework'] = (string) ($webUi['css_framework'] ?? 'bootstrap5');
        }
        if (!$hostHasIconSet) {
            $defaults['icon_set'] = (string) ($webUi['icon_set'] ?? 'bootstrap-icons');
        }
        if (!$hostHasRowActionsDisplay) {
            $defaults['row_actions_display'] = (string) ($webUi['row_actions_display'] ?? 'icon');
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

        if (!is_string($userClass) || $userClass === '') {
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
                    'attr'     => ['class' => 'nowo-ui-input form-control'],
                    'row_attr' => ['class' => 'mb-2'],
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
        $container->setParameter('nowo_blog_kit.security.manage_roles', $config['security']['manage_roles']);
        $container->setParameter('nowo_blog_kit.security.moderate_roles', $config['security']['moderate_roles']);
        $container->setParameter('nowo_blog_kit.security.configure_roles', $config['security']['configure_roles']);
        $container->setParameter('nowo_blog_kit.security.access_checker', $config['security']['access_checker']);
        $container->setParameter('nowo_blog_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_blog_kit.security.object_access.strategy', (string) $config['security']['object_access']['strategy']);
        $container->setParameter('nowo_blog_kit.security.object_access.service', $config['security']['object_access']['service']);
        $container->setParameter('nowo_blog_kit.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_blog_kit.web_ui.public_layout_template', $config['web_ui']['public_layout_template']);
        $container->setParameter('nowo_blog_kit.web_ui.css_framework', $config['web_ui']['css_framework']);
        $container->setParameter('nowo_blog_kit.web_ui.icon_set', $config['web_ui']['icon_set']);
        $container->setParameter('nowo_blog_kit.web_ui.row_actions_display', $config['web_ui']['row_actions_display']);
        $container->setParameter('nowo_blog_kit.web_ui.page_size', $config['web_ui']['page_size']);
        $container->setParameter('nowo_blog_kit.web_ui.privacy_url', $config['web_ui']['privacy_url']);
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
     *     manage_roles: list<string>,
     *     moderate_roles: list<string>,
     *     configure_roles: list<string>,
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

        $id         = 'nowo_blog_kit.access_checker.default';
        $definition = new Definition(ConfigurableBlogKitAccessChecker::class);
        $definition->setArgument('$accessRoles', $security['access_roles']);
        $definition->setArgument('$manageRoles', $security['manage_roles']);
        $definition->setArgument('$moderateRoles', $security['moderate_roles']);
        $definition->setArgument('$configureRoles', $security['configure_roles']);
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

            $container->setAlias(BlogKitResourceAccessCheckerInterface::class, $custom);

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

        $container->register(StreamCaptchaHttpClient::class)
            ->setAutowired(false)
            ->setAutoconfigured(false);
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
                $customRateLimiter !== null ? new Reference($customRateLimiter) : null,
                $customCaptcha !== null ? new Reference($customCaptcha) : null,
                $customSanitizer !== null ? new Reference($customSanitizer) : null,
            ]);

        $container->register(PublicBlogCommentCaptchaTypeExtension::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([new Reference(BlogProtection::class)])
            ->addTag('form.type_extension', ['extended_type' => PublicBlogCommentType::class]);
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
