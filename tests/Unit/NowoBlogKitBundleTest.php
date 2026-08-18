<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\NowoBlogKitBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoBlogKitBundleTest extends TestCase
{
    #[Test]
    public function buildAddsBundleCompilerPasses(): void
    {
        $container = new ContainerBuilder();

        (new NowoBlogKitBundle())->build($container);

        $passes  = $container->getCompiler()->getPassConfig()->getPasses();
        $classes = array_map(static fn (object $pass): string => $pass::class, $passes);

        self::assertContains(TwigPathsPass::class, $classes);
        self::assertContains(DoctrineOrmMappingsPass::class, $classes);
    }

    #[Test]
    public function getContainerExtensionReturnsBundleExtension(): void
    {
        $bundle = new NowoBlogKitBundle();

        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoBlogKitExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
