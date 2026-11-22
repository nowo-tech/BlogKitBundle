<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\BlogKitBundle\NowoBlogKitBundle;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Tests\Support\AuditedTestUser;
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
 * - {@see self::STANDARD}: a standard Symfony host with zero Doctrine configuration for the bundle. Attribute mapping
 *   on the default manager, `user_reference: entity` (`MappedTestUser` Doctrine entity), the built-in `owner` object
 *   access. Since 2.0 (REQ-ARCH-001 rule 6) the blog writes through the bundle's private manager on the default
 *   manager's connection, mapping and tables: the default manager is never flushed by the bundle.
 * - {@see self::DDD}: a strict DDD host. The blog on its own manager `blog` (`doctrine.entity_manager`), XML mapping,
 *   `user_reference: id` with plain PHP users, and twin-only host ports (snapshots / request VOs). The host's default
 *   manager maps only the host's `HostNote`.
 * - {@see self::PRIVATE}: the standard host with `doctrine.private_entity_manager: true` (the 1.12 opt-in; since 2.0 a
 *   deprecated no-op): the same wiring as {@see self::STANDARD}, plus the deprecation of the key.
 *
 * `$databaseUrl` runs it on a real server (the database group); `$fixtures` registers a directory of
 * `tests/Fixtures/RemovedPorts` by resource autodiscovery (autowired, autoconfigured), as a host's `services.yaml` does;
 * `$auditKitBlameMode` registers NowoAuditKitBundle 2.x (`attribute_opt_in: true`, one profile on `AuditedTestUser`, also
 * the blog's `user_class` then).
 */
final class WorkerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string STANDARD = 'standard';

    public const string DDD = 'ddd';

    public const string PRIVATE = 'private';

    public const array USER_IDS = [
        self::STANDARD => ['alice' => 1, 'bob' => 2],
        self::DDD      => ['alice' => 'u-alice', 'bob' => 'u-bob'],
        self::PRIVATE  => ['alice' => 1, 'bob' => 2],
    ];

    public function __construct(
        private readonly string $mode,
        private readonly string $directory,
        private readonly ?string $databaseUrl = null,
        private readonly ?string $fixtures = null,
        private readonly ?string $auditKitBlameMode = null,
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
            ...($this->auditKitBlameMode !== null ? [new NowoAuditKitBundle()] : []),
        ];
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-blog-kit-worker/' . md5($this->directory . $this->databaseUrl . $this->fixtures . $this->auditKitBlameMode) . '/' . $this->mode . '/cache';
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
            'dbal' => ['url' => $this->databaseUrl ?? 'sqlite:///' . $this->directory . DIRECTORY_SEPARATOR . 'blog.sqlite'],
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
            'user_class' => $ddd ? null : ($this->auditKitBlameMode !== null ? AuditedTestUser::class : MappedTestUser::class),
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

        if ($this->mode === self::PRIVATE) {
            $blog['doctrine'] = ['private_entity_manager' => true];
        }

        $container->extension('nowo_blog_kit', $blog);

        if ($this->auditKitBlameMode !== null) {
            $container->extension('nowo_audit_kit', [
                'blame_mode'       => $this->auditKitBlameMode,
                'attribute_opt_in' => true,
                'profiles'         => ['default' => ['user_class' => AuditedTestUser::class]],
            ]);
        }

        if ($this->fixtures !== null) {
            $container->services()->defaults()->autowire()->autoconfigure()
                ->load('Nowo\\BlogKitBundle\\Tests\\Fixtures\\RemovedPorts\\' . $this->fixtures . '\\', '%kernel.project_dir%/tests/Fixtures/RemovedPorts/' . $this->fixtures . '/*');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoBlogKitBundle/Resources/config/routing.yaml');
    }
}
