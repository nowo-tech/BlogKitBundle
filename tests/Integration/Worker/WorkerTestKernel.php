<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\BlogKitBundle\NowoBlogKitBundle;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\Tests\Support\Twin\FixedWindowRateLimitPolicy;
use Nowo\BlogKitBundle\Tests\Support\Twin\OwnerResourcePolicy;
use Nowo\BlogKitBundle\Tests\Support\Twin\TurnstileVerifier;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function md5;
use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

/**
 * A real kernel for the kept-alive (worker mode, kernel reset false) and DDD host tests.
 *
 * - {@see self::STANDARD}: a standard Symfony host. Attribute mapping on the default manager, `user_reference: entity`
 *   (`MappedTestUser` Doctrine entity), the built-in `owner` object access, the 1.x ports.
 * - {@see self::DDD}: a strict DDD host. The blog on its own manager `blog` (`doctrine.entity_manager`), XML mapping,
 *   `user_reference: id` with plain PHP users, and twin-only host ports (snapshots / request VOs). The host's default
 *   manager maps only the host's `HostNote`.
 */
final class WorkerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string STANDARD = 'standard';

    public const string DDD = 'ddd';

    public const array USER_IDS = [
        self::STANDARD => ['alice' => 1, 'bob' => 2],
        self::DDD      => ['alice' => 'u-alice', 'bob' => 'u-bob'],
    ];

    public function __construct(
        private readonly string $mode,
        private readonly string $directory,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new SecurityBundle(),
            new DoctrineBundle(),
            new NowoUiKitBundle(),
            new NowoFormKitBundle(),
            new NowoBlogKitBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-blog-kit-worker/' . md5($this->directory) . '/' . $this->mode . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-blog-kit-worker/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $ddd = $this->mode === self::DDD;
        $container->extension('framework', [
            'test'                 => true,
            'secret'               => 'REDACTED-TEST',
            'http_method_override' => false,
            'csrf_protection'      => true,
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'form'                 => ['csrf_protection' => true],
        ]);

        $support = [
            'type'      => 'attribute',
            'is_bundle' => false,
            'dir'       => '%kernel.project_dir%/tests/Support',
            'prefix'    => 'Nowo\\BlogKitBundle\\Tests\\Support',
        ];
        $host = [
            'type'      => 'attribute',
            'is_bundle' => false,
            'dir'       => '%kernel.project_dir%/tests/Integration/Worker/Entity',
            'prefix'    => 'Nowo\\BlogKitBundle\\Tests\\Integration\\Worker\\Entity',
        ];
        $naming = 'doctrine.orm.naming_strategy.underscore_number_aware';
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///' . $this->directory . DIRECTORY_SEPARATOR . 'blog.sqlite'],
            'orm'  => [
                'default_entity_manager' => 'default',
                'entity_managers'        => $ddd
                    ? [
                        'default' => ['naming_strategy' => $naming, 'mappings' => ['HostWorker' => $host]],
                        'blog'    => ['naming_strategy' => $naming, 'connection' => 'default'],
                    ]
                    : [
                        'default' => ['naming_strategy' => $naming, 'mappings' => ['BlogKitTestUser' => $support, 'HostWorker' => $host]],
                    ],
            ],
        ]);

        $container->services()->set(WorkerHeaderAuthenticator::class)->args([self::USER_IDS[$this->mode]]);
        $container->extension('security', [
            'providers' => ['none' => ['memory' => ['users' => []]]],
            'firewalls' => ['main' => [
                'pattern'               => '^/',
                'stateless'             => true,
                'provider'              => 'none',
                'custom_authenticators' => [WorkerHeaderAuthenticator::class],
            ]],
        ]);

        $blog = [
            'user_class' => $ddd ? null : MappedTestUser::class,
            'security'   => [
                'access_roles'  => [],
                'roles'         => ['manage' => ['ROLE_EDITOR'], 'moderate' => ['ROLE_EDITOR'], 'configure' => ['ROLE_CONFIG']],
                'object_access' => $ddd ? ['strategy' => 'service', 'service' => 'app.blog_policy'] : ['strategy' => 'owner'],
            ],
        ];
        if ($ddd) {
            $services = $container->services();
            $services->set('app.blog_policy', OwnerResourcePolicy::class)
                ->args([service(BlogKitAccessCheckerInterface::class), service('security.token_storage')]);
            $services->set('app.blog_rate_limit', FixedWindowRateLimitPolicy::class)
                ->args([inline_service(ArrayAdapter::class), inline_service(NativeClock::class), 5, 60]);
            $services->set('app.blog_captcha', TurnstileVerifier::class)
                ->args([service(CaptchaHttpClientInterface::class), 'site', 'secret']);
            $blog['doctrine'] = ['entity_manager' => 'blog', 'mapping_type' => 'xml', 'user_reference' => 'id'];
            $blog['comments'] = [
                'rate_limit' => ['strategy' => 'service', 'service' => 'app.blog_rate_limit'],
                'captcha'    => ['strategy' => 'service', 'service' => 'app.blog_captcha'],
            ];
        }

        $container->extension('nowo_blog_kit', $blog);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoBlogKitBundle/Resources/config/routing.yaml');
    }
}
