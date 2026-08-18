<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Twig\Loader\FilesystemLoader;

use function dirname;

final class TwigPathsPassTest extends TestCase
{
    #[Test]
    public function processSkipsWhenTwigLoaderIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native'));
    }

    #[Test]
    public function processAlwaysRegistersBundleViewsOnNativeLoaderWithoutFeatureFlag(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native', new Definition(FilesystemLoader::class));

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native')->getMethodCalls();

        self::assertNotEmpty($calls);
        self::assertSame('addPath', $calls[array_key_last($calls)][0]);
        self::assertSame(dirname(__DIR__, 4) . '/src/Resources/views', $calls[array_key_last($calls)][1][0]);
        self::assertSame('NowoBlogKitBundle', TwigPathsPass::TWIG_NAMESPACE);
        self::assertSame('NowoBlogKitBundle', $calls[array_key_last($calls)][1][1]);
    }

    #[Test]
    public function processPrependsOverridePathWhenDirectoryExists(): void
    {
        $projectDir   = sys_get_temp_dir() . '/blog_kit_twig_' . uniqid('', true);
        $overridePath = $projectDir . '/templates/bundles/NowoBlogKitBundle';
        mkdir($overridePath, 0777, true);

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $projectDir);
            $container->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));

            (new TwigPathsPass())->process($container);

            $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();

            self::assertSame('prependPath', $calls[0][0]);
            self::assertSame($overridePath, $calls[0][1][0]);
            self::assertSame('NowoBlogKitBundle', $calls[0][1][1]);
            self::assertSame('addPath', $calls[1][0]);
        } finally {
            @rmdir($overridePath);
            @rmdir(dirname($overridePath));
            @rmdir(dirname($overridePath, 2));
            @rmdir($projectDir);
        }
    }

    #[Test]
    public function processResolvesTwigLoaderAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());
    }

    #[Test]
    public function processResolvesTwigLoaderAliasChain(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.actual', new Definition(FilesystemLoader::class));
        $container->setAlias('twig.loader.first', 'twig.loader.actual');
        $container->setAlias('twig.loader.native', 'twig.loader.first');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($container->getDefinition('twig.loader.actual')->getMethodCalls());
    }

    #[Test]
    public function processFallsBackToTwigFilesystemLoaderDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.filesystem', new Definition(FilesystemLoader::class));

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.filesystem')->getMethodCalls();

        self::assertCount(1, $calls);
        self::assertSame('addPath', $calls[0][0]);
        self::assertSame(dirname(__DIR__, 4) . '/src/Resources/views', $calls[0][1][0]);
        self::assertSame('NowoBlogKitBundle', $calls[0][1][1]);
    }
}
