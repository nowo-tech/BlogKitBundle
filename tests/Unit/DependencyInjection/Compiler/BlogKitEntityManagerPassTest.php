<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitEntityManagerPass;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;

final class BlogKitEntityManagerPassTest extends TestCase
{
    #[Test]
    public function withoutTheOptionNothingChanges(): void
    {
        $container = $this->container(null);
        new BlogKitEntityManagerPass()->process($container);
        new BlogKitEntityManagerPass()->process(new ContainerBuilder());

        self::assertCount(3, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    #[Test]
    public function mapsTheEntitiesOnTheConfiguredManagerOnly(): void
    {
        $container = $this->container('blog');
        new BlogKitEntityManagerPass(checkOnly: true)->process($container);
        self::assertCount(3, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls(), 'check only');

        new BlogKitEntityManagerPass()->process($container);

        $default = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls();
        self::assertSame([['addDriver', BlogKitEntityManagerPass::ENTITY_NAMESPACE], ['addDriver', 'App\\Entity'], ['setDefaultDriver', null]], array_map(static fn (array $call): array => [$call[0], $call[1][1] ?? null], $default));
        self::assertInstanceOf(Definition::class, $default[0][1][0]);
        self::assertSame(ExcludedEntityNamespaceDriver::class, $default[0][1][0]->getClass());
        self::assertCount(1, $container->getDefinition('doctrine.orm.blog_metadata_driver')->getMethodCalls());
    }

    #[Test]
    public function unknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"reports", but no such Doctrine entity manager');
        new BlogKitEntityManagerPass()->process($this->container('reports'));
    }

    #[Test]
    public function noManagersAtAllFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER, 'blog');

        $this->expectException(InvalidConfigurationException::class);
        new BlogKitEntityManagerPass()->process($container);
    }

    #[Test]
    public function theDefaultManagerIsRefused(): void
    {
        $container = $this->container('default');
        $container->setParameter('doctrine.default_entity_manager', 'default');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('your default Doctrine entity manager');
        new BlogKitEntityManagerPass()->process($container);
    }

    #[Test]
    public function aManagerThatDoesNotMapTheBlogFailsTheBuild(): void
    {
        $container = $this->container('blog');
        $container->getDefinition('doctrine.orm.blog_metadata_driver')->setMethodCalls([]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');
        new BlogKitEntityManagerPass()->process($container);
    }

    #[Test]
    public function aManagerThatAlsoMapsOtherClassesFailsTheBuild(): void
    {
        $container = $this->container('blog');
        $container->getDefinition('doctrine.orm.blog_metadata_driver')
            ->addMethodCall('addDriver', [new Reference('attribute'), 'App\\Entity'])
            ->addMethodCall('setDefaultDriver', [new Reference('auto_discover')]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('also maps App\\Entity, a default driver (every class)');
        new BlogKitEntityManagerPass()->process($container);
    }

    private function container(?string $name): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER, $name);
        $container->setParameter('doctrine.entity_managers', [
            'default'  => 'doctrine.orm.default_entity_manager',
            'blog'     => 'doctrine.orm.blog_entity_manager',
            'no_chain' => 'doctrine.orm.no_chain_entity_manager',
        ]);
        $container->setDefinition('doctrine.orm.default_metadata_driver', new Definition()
            ->addMethodCall('addDriver', [new Reference('attribute'), 'App\\Entity'])
            ->addMethodCall('addDriver', [new Reference('attribute'), BlogKitEntityManagerPass::ENTITY_NAMESPACE])
            ->addMethodCall('setDefaultDriver', [new Reference('auto_discover')]));
        $container->setDefinition('doctrine.orm.blog_metadata_driver', new Definition()
            ->addMethodCall('addDriver', [new Reference('xml'), BlogKitEntityManagerPass::ENTITY_NAMESPACE]));

        return $container;
    }
}
