<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitEntityManagerPass;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitPortsPass;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
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
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new BlogKitPortsPass());

        // Since 1.9.0: attribute (default) or XML mapping, on the default manager or on doctrine.entity_manager. The
        // extension sets exactly one of the four enabling parameters.
        $namespace = BlogKitEntityManagerPass::ENTITY_NAMESPACE;
        $entities  = __DIR__ . '/Entity';
        $xml       = __DIR__ . '/Resources/config/doctrine-xml';
        $dedicated = [NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER];

        $container->addCompilerPass(new BlogKitEntityManagerPass(checkOnly: true), PassConfig::TYPE_BEFORE_OPTIMIZATION, 20);
        $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver([$namespace], [$entities], [], NowoBlogKitExtension::MAPPING_ATTRIBUTE_DEFAULT));
        $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver([$xml => $namespace], [], NowoBlogKitExtension::MAPPING_XML_DEFAULT));
        $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver([$namespace], [$entities], $dedicated, NowoBlogKitExtension::MAPPING_ATTRIBUTE_DEDICATED));
        $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver([$xml => $namespace], $dedicated, NowoBlogKitExtension::MAPPING_XML_DEDICATED));
        $container->addCompilerPass(new BlogKitEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof NowoBlogKitExtension) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoBlogKitExtension();
        }

        return $this->extension;
    }
}
