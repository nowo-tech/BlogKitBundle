<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\Events;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Doctrine\BlogKitEntityManagerFactory;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Doctrine\BlogUserReferenceMetadataListener;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\Worker\BlogKitOwnEntityManagerSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * REQ-ARCH-001 rules 2 and 6 (1.9.0): `doctrine.user_reference`, `doctrine.mapping_type`, `doctrine.entity_manager`,
 * `user_directory`.
 */
final class NowoBlogKitExtensionPersistenceTest extends TestCase
{
    #[Test]
    public function theDefaultsKeepThe18Wiring(): void
    {
        $container = $this->load([]);

        self::assertNull($container->getParameter(NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER));
        self::assertSame('attribute', $container->getParameter('nowo_blog_kit.doctrine.mapping_type'));
        self::assertSame('entity', $container->getParameter('nowo_blog_kit.doctrine.user_reference'));
        self::assertTrue($container->hasParameter(NowoBlogKitExtension::MAPPING_ATTRIBUTE_DEFAULT));
        self::assertFalse($container->hasParameter(NowoBlogKitExtension::MAPPING_XML_DEFAULT));
        self::assertEquals(
            ['entity', new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE), null],
            $container->getDefinition(BlogUsers::class)->getArguments(),
        );
        self::assertSame(BlogKitEntityManagerFactory::class . '::create', implode('::', (array) $container->getDefinition(NowoBlogKitExtension::ENTITY_MANAGER_SERVICE)->getFactory()));
        self::assertFalse($this->registered($container, BlogUserReferenceMetadataListener::class));
        self::assertFalse($this->registered($container, BlogUserBlameListener::class));
        self::assertFalse($this->registered($container, BlogKitOwnEntityManagerSubscriber::class));
    }

    #[Test]
    public function idModeXmlAndDirectory(): void
    {
        $container = $this->load(['user_directory' => 'app.blog_users', 'doctrine' => ['user_reference' => 'id', 'mapping_type' => 'xml']]);

        self::assertTrue($container->hasParameter(NowoBlogKitExtension::MAPPING_XML_DEFAULT));
        self::assertEquals(new Reference('app.blog_users'), $container->getDefinition(BlogUsers::class)->getArgument(2));
        self::assertSame([['event' => Events::loadClassMetadata]], $container->getDefinition(BlogUserReferenceMetadataListener::class)->getTag('doctrine.event_listener'));
        self::assertSame([['event' => Events::onFlush]], $container->getDefinition(BlogUserBlameListener::class)->getTag('doctrine.event_listener'));
    }

    #[Test]
    public function aDedicatedManagerIsTheBundlesOwn(): void
    {
        $container = $this->load(['doctrine' => ['user_reference' => 'id', 'mapping_type' => 'xml', 'entity_manager' => 'blog']]);

        self::assertSame('blog', $container->getParameter(NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER));
        self::assertTrue($container->hasParameter(NowoBlogKitExtension::MAPPING_XML_DEDICATED));
        self::assertSame('doctrine.orm.blog_entity_manager', (string) $container->getAlias(NowoBlogKitExtension::ENTITY_MANAGER_SERVICE));
        $subscriber = $container->getDefinition(BlogKitOwnEntityManagerSubscriber::class);
        self::assertTrue($subscriber->hasTag('kernel.event_subscriber'));
        self::assertSame('blog', $subscriber->getArgument(3));

        $attribute = $this->load(['doctrine' => ['user_reference' => 'id', 'entity_manager' => 'blog']]);
        self::assertTrue($attribute->hasParameter(NowoBlogKitExtension::MAPPING_ATTRIBUTE_DEDICATED));
    }

    #[Test]
    public function aDedicatedManagerNeedsTheIdMode(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs nowo_blog_kit.doctrine.user_reference: id');
        $this->load(['doctrine' => ['entity_manager' => 'blog']]);
    }

    #[Test]
    public function prependRegistersTheTypeAndSkipsTheRelationInIdMode(): void
    {
        $container = $this->prepend(['user_class' => 'App\\Entity\\User', 'doctrine' => ['user_reference' => 'id']], []);

        self::assertSame([['dbal' => ['types' => [BlogUserRefType::NAME => BlogUserRefType::class]]]], $container->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function prependTurnsOffEntityAutoDiscoveryOnTheBundlesManager(): void
    {
        $doctrine = [['orm' => ['entity_managers' => ['default' => [], 'blog' => []]]]];
        $config   = ['doctrine' => ['user_reference' => 'id', 'entity_manager' => 'blog']];

        $container = $this->prepend($config, $doctrine);
        $configs   = $container->getExtensionConfig('doctrine');
        if (NowoBlogKitExtension::supportsEntityAutoDiscovery()) {
            self::assertContains(['orm' => ['entity_managers' => ['blog' => ['auto_discover_entities' => false]]]], $configs);
        } else {
            self::assertNotContains(['orm' => ['entity_managers' => ['blog' => ['auto_discover_entities' => false]]]], $configs);
        }

        // The host's own setting wins; an undeclared manager is reported by the compiler pass, not here.
        foreach ([[['orm' => ['entity_managers' => ['blog' => ['auto_discover_entities' => true]]]]], [['dbal' => []], ['orm' => ['entity_managers' => ['default' => []]]]]] as $host) {
            $configs = $this->prepend($config, $host)->getExtensionConfig('doctrine');
            self::assertNotContains(['orm' => ['entity_managers' => ['blog' => ['auto_discover_entities' => false]]]], $configs);
        }
    }

    /** A definition the container keeps (excluded resources stay as `container.excluded` placeholders). */
    private function registered(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) && !$container->getDefinition($id)->hasTag('container.excluded');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new NowoBlogKitExtension()->load([['security' => ['allow_unauthenticated' => true]] + $config], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $doctrine
     */
    private function prepend(array $config, array $doctrine): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension('doctrine'));
        $container->registerExtension(new NowoBlogKitExtension());
        foreach ($doctrine as $entry) {
            $container->loadFromExtension('doctrine', $entry);
        }
        $container->loadFromExtension('nowo_blog_kit', $config);

        new NowoBlogKitExtension()->prepend($container);

        return $container;
    }

    private function extension(string $alias): Extension
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
