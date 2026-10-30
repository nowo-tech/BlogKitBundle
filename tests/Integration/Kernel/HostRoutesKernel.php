<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\BlogKitBundle\NowoBlogKitBundle;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: real kernel whose routing is the Symfony 7.3+ default `controllers: { resource:
 * routing.controllers }`, with or without the documented import of the bundle routing.yaml.
 */
final class HostRoutesKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly bool $importBundleRoutes)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
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
        return sys_get_temp_dir() . '/nowo-blog-kit-host-routes/' . ($this->importBundleRoutes ? 'imported' : 'controllers-only') . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-blog-kit-host-routes/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                 => true,
            'secret'               => 'REDACTED-TEST',
            'http_method_override' => false,
            'csrf_protection'      => true,
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'form'                 => ['csrf_protection' => true],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [
                'mappings' => [
                    'BlogKitTestUser' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => '%kernel.project_dir%/tests/Support',
                        'prefix'    => 'Nowo\\BlogKitBundle\\Tests\\Support',
                    ],
                ],
            ],
        ]);
        $container->extension('nowo_blog_kit', [
            'user_class' => MappedTestUser::class,
            'security'   => ['allow_unauthenticated' => true],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->importBundleRoutes) {
            $routes->import('@NowoBlogKitBundle/Resources/config/routing.yaml');
        }
        $routes->import('routing.controllers');
    }
}
