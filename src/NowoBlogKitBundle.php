<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle entry point for the reusable blog kit.
 */
class NowoBlogKitBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());

        $entityDir = __DIR__ . '/Entity';
        if (is_dir($entityDir)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['Nowo\\BlogKitBundle\\Entity'],
                [$entityDir],
            ));
        }
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof NowoBlogKitExtension) {
            $this->extension = new NowoBlogKitExtension();
        }

        return $this->extension;
    }
}
