<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\Events;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Configuration;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\DependencyInjection\TablePrefixListener;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Nowo\BlogKitBundle\Security\Captcha\PublicBlogCommentCaptchaTypeExtension;
use Nowo\BlogKitBundle\Security\ConfigurableBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\OwnerBlogKitResourceAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use function dirname;

final class NowoBlogKitExtensionTest extends TestCase
{
    #[Test]
    public function getAliasReturnsConfigurationAlias(): void
    {
        $extension = new NowoBlogKitExtension();

        self::assertSame(Configuration::ALIAS, $extension->getAlias());
        self::assertSame('nowo_blog_kit', $extension->getAlias());
    }

    #[Test]
    public function loadSetsParametersFromDefaultsWhenUnauthenticatedAccessIsAllowed(): void
    {
        $container = new ContainerBuilder();

        (new NowoBlogKitExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertNull($container->getParameter('nowo_blog_kit.user_class'));
        self::assertSame('es', $container->getParameter('nowo_blog_kit.default_locale'));
        self::assertSame(['es', 'en'], $container->getParameter('nowo_blog_kit.locales'));
        self::assertSame('', $container->getParameter('nowo_blog_kit.doctrine.table_prefix'));
        self::assertSame('default', $container->getParameter('nowo_blog_kit.doctrine.connection'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_blog_kit.security.access_roles'));
        self::assertSame(['ROLE_EDITOR'], $container->getParameter('nowo_blog_kit.security.manage_roles'));
        self::assertSame(['ROLE_MODERATOR'], $container->getParameter('nowo_blog_kit.security.moderate_roles'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_blog_kit.security.configure_roles'));
        self::assertNull($container->getParameter('nowo_blog_kit.security.access_checker'));
        self::assertTrue($container->getParameter('nowo_blog_kit.security.allow_unauthenticated'));
        self::assertSame('none', $container->getParameter('nowo_blog_kit.security.object_access.strategy'));
        self::assertNull($container->getParameter('nowo_blog_kit.security.object_access.service'));
        self::assertSame('bootstrap5', $container->getParameter('nowo_blog_kit.web_ui.css_framework'));
        self::assertSame('bootstrap-icons', $container->getParameter('nowo_blog_kit.web_ui.icon_set'));
        self::assertSame('icon', $container->getParameter('nowo_blog_kit.web_ui.row_actions_display'));
        self::assertSame(20, $container->getParameter('nowo_blog_kit.web_ui.page_size'));
        self::assertSame('#', $container->getParameter('nowo_blog_kit.web_ui.privacy_url'));
        self::assertSame('paginated', $container->getParameter('nowo_blog_kit.listing.mode'));
        self::assertSame('masonry', $container->getParameter('nowo_blog_kit.listing.masonry.strategy'));
        self::assertSame(1, $container->getParameter('nowo_blog_kit.listing.masonry.columns_mobile'));
        self::assertSame(2, $container->getParameter('nowo_blog_kit.listing.masonry.columns_tablet'));
        self::assertSame(2, $container->getParameter('nowo_blog_kit.listing.masonry.columns_desktop'));
        self::assertTrue($container->hasDefinition(BlogProtection::class));
        self::assertTrue($container->hasDefinition(PublicBlogCommentCaptchaTypeExtension::class));
    }

    #[Test]
    public function loadPublishesListingModeFromConfiguration(): void
    {
        $container = new ContainerBuilder();

        (new NowoBlogKitExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'listing'  => ['mode' => 'infinite'],
        ]], $container);

        self::assertSame('infinite', $container->getParameter('nowo_blog_kit.listing.mode'));
    }

    #[Test]
    public function loadPublishesMasonryFromConfiguration(): void
    {
        $container = new ContainerBuilder();

        (new NowoBlogKitExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'listing'  => [
                'masonry' => [
                    'strategy'        => 'grid',
                    'columns_mobile'  => 2,
                    'columns_tablet'  => 2,
                    'columns_desktop' => 3,
                ],
            ],
        ]], $container);

        self::assertSame('grid', $container->getParameter('nowo_blog_kit.listing.masonry.strategy'));
        self::assertSame(2, $container->getParameter('nowo_blog_kit.listing.masonry.columns_mobile'));
        self::assertSame(2, $container->getParameter('nowo_blog_kit.listing.masonry.columns_tablet'));
        self::assertSame(3, $container->getParameter('nowo_blog_kit.listing.masonry.columns_desktop'));
    }

    #[Test]
    public function loadThrowsLogicExceptionWithoutSecurityBundleWhenUnauthenticatedAccessIsDisabled(): void
    {
        $container = new ContainerBuilder();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');

        (new NowoBlogKitExtension())->load([[]], $container);
    }

    #[Test]
    public function loadAcceptsKernelBundlesSecurityBundleHint(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new NowoBlogKitExtension())->load([[]], $container);

        self::assertSame('es', $container->getParameter('nowo_blog_kit.default_locale'));
        self::assertSame(
            'nowo_blog_kit.access_checker.default',
            (string) $container->getAlias(BlogKitAccessCheckerInterface::class),
        );
    }

    #[Test]
    public function loadRegistersAllowAllAccessCheckerWhenUnauthenticatedAccessIsAllowed(): void
    {
        $container = new ContainerBuilder();

        (new NowoBlogKitExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertTrue($container->hasDefinition('nowo_blog_kit.access_checker.allow_all'));
        self::assertSame(
            AllowAllBlogKitAccessChecker::class,
            $container->getDefinition('nowo_blog_kit.access_checker.allow_all')->getClass(),
        );
        self::assertSame(
            'nowo_blog_kit.access_checker.allow_all',
            (string) $container->getAlias(BlogKitAccessCheckerInterface::class),
        );
        self::assertSame(
            'nowo_blog_kit.object_access.allow_all',
            (string) $container->getAlias(BlogKitResourceAccessCheckerInterface::class),
        );
        self::assertFalse($container->hasDefinition('nowo_blog_kit.access_checker.default'));
    }

    #[Test]
    public function loadUsesCustomAccessCheckerAliasWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('security'));

        (new NowoBlogKitExtension())->load([[
            'security' => [
                'access_checker' => 'app.blog_access_checker',
            ],
        ]], $container);

        self::assertSame('app.blog_access_checker', (string) $container->getAlias(BlogKitAccessCheckerInterface::class));
        self::assertFalse($container->hasDefinition('nowo_blog_kit.access_checker.default'));
    }

    #[Test]
    public function loadRegistersDefaultConfigurableAccessCheckerWhenSecurityIsAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('security'));

        (new NowoBlogKitExtension())->load([[]], $container);

        $definition = $container->getDefinition('nowo_blog_kit.access_checker.default');

        self::assertSame(ConfigurableBlogKitAccessChecker::class, $definition->getClass());
        self::assertSame(['ROLE_ADMIN'], $definition->getArgument('$accessRoles'));
        self::assertSame(['ROLE_EDITOR'], $definition->getArgument('$manageRoles'));
        self::assertSame(['ROLE_MODERATOR'], $definition->getArgument('$moderateRoles'));
        self::assertSame(['ROLE_ADMIN'], $definition->getArgument('$configureRoles'));
        self::assertEquals(new Reference('security.authorization_checker'), $definition->getArgument('$authorizationChecker'));
        self::assertSame(
            'nowo_blog_kit.access_checker.default',
            (string) $container->getAlias(BlogKitAccessCheckerInterface::class),
        );
        self::assertSame(
            AllowAllBlogKitResourceAccessChecker::class,
            $container->getDefinition('nowo_blog_kit.object_access.allow_all')->getClass(),
        );
        self::assertSame(
            'nowo_blog_kit.object_access.allow_all',
            (string) $container->getAlias(BlogKitResourceAccessCheckerInterface::class),
        );
    }

    #[Test]
    public function loadRegistersOwnerObjectAccessWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('security'));

        (new NowoBlogKitExtension())->load([[
            'security' => [
                'object_access' => ['strategy' => 'owner'],
            ],
        ]], $container);

        $definition = $container->getDefinition('nowo_blog_kit.object_access.owner');

        self::assertSame(OwnerBlogKitResourceAccessChecker::class, $definition->getClass());
        self::assertEquals(
            new Reference(BlogKitAccessCheckerInterface::class),
            $definition->getArgument('$accessChecker'),
        );
        self::assertSame(
            'nowo_blog_kit.object_access.owner',
            (string) $container->getAlias(BlogKitResourceAccessCheckerInterface::class),
        );
    }

    #[Test]
    public function loadUsesCustomObjectAccessAliasWhenStrategyIsService(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('security'));

        (new NowoBlogKitExtension())->load([[
            'security' => [
                'object_access' => [
                    'strategy' => 'service',
                    'service'  => 'app.blog_object_access',
                ],
            ],
        ]], $container);

        self::assertSame(
            'app.blog_object_access',
            (string) $container->getAlias(BlogKitResourceAccessCheckerInterface::class),
        );
        self::assertFalse($container->hasDefinition('nowo_blog_kit.object_access.owner'));
    }

    #[Test]
    public function loadThrowsWhenObjectAccessStrategyIsServiceWithoutServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('security'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('object_access.service is required');

        (new NowoBlogKitExtension())->load([[
            'security' => [
                'object_access' => [
                    'strategy' => 'service',
                    'service'  => '',
                ],
            ],
        ]], $container);
    }

    #[Test]
    public function loadKeepsAllowAllObjectAccessWhenUnauthenticatedEvenIfOwnerIsConfigured(): void
    {
        $container = new ContainerBuilder();

        (new NowoBlogKitExtension())->load([[
            'security' => [
                'allow_unauthenticated' => true,
                'object_access'         => ['strategy' => 'owner'],
            ],
        ]], $container);

        self::assertSame('owner', $container->getParameter('nowo_blog_kit.security.object_access.strategy'));
        self::assertSame(
            'nowo_blog_kit.object_access.allow_all',
            (string) $container->getAlias(BlogKitResourceAccessCheckerInterface::class),
        );
        self::assertFalse($container->hasDefinition('nowo_blog_kit.object_access.owner'));
    }

    #[Test]
    public function loadWiresCustomProtectionServicesWhenStrategyIsService(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new NowoBlogKitExtension())->load([[
            'comments' => [
                'rate_limit' => [
                    'strategy' => 'service',
                    'service'  => 'app.blog_rate_limiter',
                ],
                'captcha' => [
                    'strategy' => 'service',
                    'service'  => 'app.blog_captcha',
                ],
            ],
            'html' => [
                'sanitize' => [
                    'strategy' => 'service',
                    'service'  => 'app.blog_sanitizer',
                ],
            ],
        ]], $container);

        $arguments = $container->getDefinition(BlogProtection::class)->getArguments();
        self::assertEquals(new Reference('app.blog_rate_limiter'), $arguments[6]);
        self::assertEquals(new Reference('app.blog_captcha'), $arguments[7]);
        self::assertEquals(new Reference('app.blog_sanitizer'), $arguments[8]);
    }

    #[Test]
    public function loadIgnoresEmptyProtectionServiceIds(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new NowoBlogKitExtension())->load([[
            'comments' => [
                'rate_limit' => ['service' => ''],
                'captcha'    => ['service' => ''],
            ],
            'html' => [
                'sanitize' => ['service' => ''],
            ],
        ]], $container);

        $arguments = $container->getDefinition(BlogProtection::class)->getArguments();
        self::assertNull($arguments[6]);
        self::assertNull($arguments[7]);
        self::assertNull($arguments[8]);
    }

    #[Test]
    public function loadRegistersTablePrefixListenerWhenConfigured(): void
    {
        $container = new ContainerBuilder();

        (new NowoBlogKitExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'doctrine' => ['table_prefix' => 'bk_'],
        ]], $container);

        self::assertTrue($container->hasDefinition(TablePrefixListener::class));

        $definition = $container->getDefinition(TablePrefixListener::class);

        self::assertSame(TablePrefixListener::class, $definition->getClass());
        self::assertSame('bk_', $definition->getArgument(0));
        self::assertSame(
            [['event' => Events::loadClassMetadata]],
            $definition->getTag('doctrine.event_listener'),
        );
    }

    #[Test]
    public function prependSeedsFormKitBlogKitProfileWhenMissing(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_form_kit'));

        (new NowoBlogKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertNotEmpty($configs);

        $merged = array_replace_recursive(...array_reverse($configs));

        self::assertSame('blog_kit', $merged['profiles']['blog_kit']['alias']);
        self::assertSame('NowoBlogKitBundle', $merged['profiles']['blog_kit']['translation_domain']);
        self::assertSame('filter', $merged['profiles']['filter']['alias']);
        self::assertTrue($merged['profiles']['filter']['auto_placeholder']);
        self::assertSame(EntityType::class, $merged['type_map']['entity']);
    }

    #[Test]
    public function prependDoesNotOverrideHostFormKitProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_form_kit'));
        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [
                'blog_kit' => [
                    'alias' => 'custom_blog_kit',
                ],
            ],
        ]);

        (new NowoBlogKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        $merged  = array_replace_recursive(...array_reverse($configs));

        self::assertSame('custom_blog_kit', $merged['profiles']['blog_kit']['alias']);
        self::assertSame('filter', $merged['profiles']['filter']['alias']);
    }

    #[Test]
    public function prependAddsOnlyMissingBlogKitProfileWhenFilterProfileAlreadyExists(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_form_kit'));
        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [
                'filter' => [
                    'alias' => 'custom_filter',
                ],
            ],
        ]);

        (new NowoBlogKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        $merged  = array_replace_recursive(...array_reverse($configs));

        self::assertSame('blog_kit', $merged['profiles']['blog_kit']['alias']);
        self::assertSame('custom_filter', $merged['profiles']['filter']['alias']);
    }

    #[Test]
    public function prependSkipsFormKitDefaultsWhenHostAlreadyProvidesBothProfiles(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_form_kit'));
        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [
                'blog_kit' => [
                    'alias' => 'host_blog_kit',
                ],
                'filter' => [
                    'alias' => 'host_filter',
                ],
            ],
        ]);

        (new NowoBlogKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        $merged  = array_replace_recursive(...array_reverse($configs));

        self::assertSame('host_blog_kit', $merged['profiles']['blog_kit']['alias']);
        self::assertSame('host_filter', $merged['profiles']['filter']['alias']);
        self::assertSame(EntityType::class, $merged['type_map']['entity']);
        self::assertFalse(isset($merged['profiles']['blog_kit']['translation_domain']));
    }

    #[Test]
    public function prependAddsDoctrineResolveTargetEntitiesWhenUserClassIsConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('doctrine'));
        $container->registerExtension(new NowoBlogKitExtension());
        $container->loadFromExtension('nowo_blog_kit', [
            'user_class' => 'App\\Entity\\User',
        ]);

        (new NowoBlogKitExtension())->prepend($container);

        self::assertSame([
            [
                'orm' => [
                    'resolve_target_entities' => [
                        BlogUserInterface::class => 'App\\Entity\\User',
                    ],
                ],
            ],
        ], $container->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function prependSkipsDoctrineResolveTargetWhenUserClassIsEmpty(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('doctrine'));
        $container->registerExtension(new NowoBlogKitExtension());
        $container->loadFromExtension('nowo_blog_kit', [
            'user_class' => '',
        ]);

        (new NowoBlogKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function prependSeedsUiKitDefaultsWhenHostDidNotConfigureThem(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->registerExtension(new NowoBlogKitExtension());
        $container->loadFromExtension('nowo_blog_kit', [
            'web_ui' => [
                'css_framework' => 'tailwind',
                'icon_set'      => 'bootstrap-icons',
            ],
        ]);

        (new NowoBlogKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        self::assertNotEmpty($configs);

        $merged = array_replace_recursive(...array_reverse($configs));

        self::assertSame('tailwind', $merged['css_framework']);
        self::assertSame('bootstrap-icons', $merged['icon_set']);
        self::assertSame('icon', $merged['row_actions_display']);
    }

    #[Test]
    public function prependDoesNotOverrideHostUiKitValues(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->registerExtension(new NowoBlogKitExtension());
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework'       => 'bootstrap5',
            'icon_set'            => 'fontawesome',
            'row_actions_display' => 'text',
        ]);
        $container->loadFromExtension('nowo_blog_kit', [
            'web_ui' => [
                'css_framework' => 'tailwind',
                'icon_set'      => 'bootstrap-icons',
            ],
        ]);

        (new NowoBlogKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');

        self::assertCount(1, $configs);
        self::assertSame('bootstrap5', $configs[0]['css_framework']);
        self::assertSame('fontawesome', $configs[0]['icon_set']);
        self::assertSame('text', $configs[0]['row_actions_display']);
    }

    #[Test]
    public function prependAddsFrameworkAssetsPackageAndTranslatorPaths(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('framework'));

        (new NowoBlogKitExtension())->prepend($container);

        $frameworkConfig = $container->getExtensionConfig('framework');
        self::assertCount(1, $frameworkConfig);
        self::assertSame('/bundles/nowoblogkit', $frameworkConfig[0]['assets']['packages']['nowo_blog_kit']['base_path']);
        self::assertSame(['en'], $frameworkConfig[0]['translator']['fallbacks']);
        self::assertSame(
            realpath(dirname(__DIR__, 3) . '/src/Resources/translations'),
            realpath($frameworkConfig[0]['translator']['paths'][0]),
        );
    }

    private function createExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $aliasName)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->aliasName;
            }
        };
    }
}
