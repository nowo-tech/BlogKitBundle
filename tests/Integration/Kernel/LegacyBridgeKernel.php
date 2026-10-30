<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\BlogKitBundle\NowoBlogKitBundle;
use Nowo\BlogKitBundle\Ui\BlogKitPages;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function sys_get_temp_dir;

/**
 * Real kernel (Framework, Twig, Doctrine, the required nowo-tech bundles and this bundle) with the page plumbing wired by the bundle configuration: the
 * `twig` service comes from TwigBundle, so the test catches autowiring mistakes that a hand-built BlogKitPages hides.
 */
final class LegacyBridgeKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @param string $templatesDir directory of the host templates (`twig.default_path`)
     */
    public function __construct(private readonly string $templatesDir, private readonly string $layout)
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
        return $this->templatesDir;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-blog-kit-legacy-bridge/' . md5($this->templatesDir . $this->layout) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-blog-kit-legacy-bridge/log';
    }

    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition(BlogKitPages::class)->setPublic(true);
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
        $container->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
        $container->extension('twig', ['default_path' => $this->templatesDir]);
        $container->extension('nowo_blog_kit', [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => ['layout_template' => $this->layout, 'public_layout_template' => $this->layout],
        ]);
    }
}
