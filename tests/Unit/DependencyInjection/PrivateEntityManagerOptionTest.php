<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\Events;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitPrivateEntityManagerPass;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Doctrine\BlogKitHostFlushReporter;
use Nowo\BlogKitBundle\Doctrine\BlogKitPrivateUserReferenceListener;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitWorkerStateSubscriber;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\BlogKitBundle\Worker\BlogKitOwnEntityManagerSubscriber;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.12.0 (REQ-ARCH-001 rule 6 deprecation layer): `doctrine.private_entity_manager`, its notices and its wiring.
 */
final class PrivateEntityManagerOptionTest extends TestCase
{
    use CollectsDeprecations;

    private const string PREFIX = 'Since nowo-tech/blog-kit-bundle 1.12.0: ';

    #[Test]
    public function unsetTriggersTheNoticeAndEnablesTheProbe(): void
    {
        $container = null;
        self::assertSame(
            [self::PREFIX . NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION],
            self::deprecationsOf(function () use (&$container): void {
                $container = $this->load([]);
            }),
        );
        self::assertInstanceOf(ContainerBuilder::class, $container);
        self::assertTrue($container->getDefinition(BlogKitHostFlushReporter::class)->getArgument(0), 'the probe is on');
        self::assertNull($container->getParameter(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertFalse($container->hasDefinition(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
    }

    #[Test]
    public function falseTriggersTheRemovedInTwoNotice(): void
    {
        $container = null;
        self::assertSame(
            [self::PREFIX . NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION],
            self::deprecationsOf(function () use (&$container): void {
                $container = $this->load(['doctrine' => ['private_entity_manager' => false]]);
            }),
        );
        self::assertInstanceOf(ContainerBuilder::class, $container);
        self::assertStringContainsString('removed in 2.0', NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION);
        self::assertTrue($container->getDefinition(BlogKitHostFlushReporter::class)->getArgument(0), 'the probe is on');
        self::assertFalse($container->getParameter(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
    }

    #[Test]
    public function aDedicatedManagerTriggersNoNoticeAndNoProbe(): void
    {
        $container = null;
        self::assertSame([], self::deprecationsOf(function () use (&$container): void {
            $container = $this->load(['doctrine' => ['user_reference' => 'id', 'entity_manager' => 'blog']]);
        }));
        self::assertInstanceOf(ContainerBuilder::class, $container);
        self::assertFalse($container->getDefinition(BlogKitHostFlushReporter::class)->getArgument(0));
    }

    #[Test]
    public function trueTriggersNoNoticeAndWiresThePrivateManager(): void
    {
        $container = null;
        self::assertSame([], self::deprecationsOf(function () use (&$container): void {
            $container = $this->load(['doctrine' => ['private_entity_manager' => true]]);
        }));
        self::assertInstanceOf(ContainerBuilder::class, $container);

        self::assertTrue($container->getParameter(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertFalse($container->getDefinition(BlogKitHostFlushReporter::class)->getArgument(0), 'no probe: the bundle never flushes your manager');

        $manager = $container->getDefinition(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertSame(PrivateEntityManager::class, $manager->getClass());
        self::assertTrue($manager->hasTag(PrivateEntityManager::TAG));
        self::assertTrue($manager->hasTag('kernel.reset'));
        self::assertSame('%' . NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER . '%', $manager->getArgument(1));
        self::assertSame(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE, (string) $container->getAlias(NowoBlogKitExtension::ENTITY_MANAGER_SERVICE));

        $registry = new Reference(NowoBlogKitExtension::PRIVATE_REGISTRY_SERVICE);
        self::assertSame(PrivateManagerRegistry::class, $container->getDefinition(NowoBlogKitExtension::PRIVATE_REGISTRY_SERVICE)->getClass());
        self::assertSame(BlogKitHostFlushReporter::OWN_NAMESPACES, $container->getDefinition(NowoBlogKitExtension::PRIVATE_REGISTRY_SERVICE)->getArgument(2));
        foreach ([BlogArticleRepository::class, BlogCommentRepository::class, BlogSettingsRepository::class] as $repository) {
            self::assertEquals($registry, $container->getDefinition($repository)->getArgument('$registry'), $repository);
        }

        self::assertEquals($registry, $container->getDefinition(BlogKitTransactionRunner::class)->getArgument('$registry'));
        self::assertEquals($registry, $container->getDefinition(BlogKitWorkerStateSubscriber::class)->getArgument('$managerRegistry'));

        $middleware = $container->getDefinition(NowoBlogKitExtension::PRIVATE_MIDDLEWARE_SERVICE);
        self::assertSame(PrivateEntityManagerMiddleware::class, $middleware->getClass());
        self::assertSame(NowoBlogKitExtension::OWN_SAVEPOINT_PREFIX, $middleware->getArgument(1));

        self::assertSame([['event' => Events::preFlush]], $container->getDefinition(BlogKitPrivateUserReferenceListener::class)->getTag('doctrine.event_listener'));

        $resettables = $container->getDefinition(BlogKitOwnEntityManagerSubscriber::class)->getArgument(4);
        self::assertInstanceOf(IteratorArgument::class, $resettables);
        self::assertSame(
            [BlogKitHostFlushReporter::class, NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE],
            array_map(static fn (Reference $reference): string => (string) $reference, $resettables->getValues()),
            'both are reset at every boundary',
        );
    }

    #[Test]
    public function theIdModeNeedsNoUserReferenceListener(): void
    {
        $container = $this->load(['doctrine' => ['private_entity_manager' => true, 'user_reference' => 'id']]);

        self::assertFalse($container->hasDefinition(BlogKitPrivateUserReferenceListener::class));
    }

    #[Test]
    public function trueCannotBeCombinedWithADedicatedManager(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be combined with nowo_blog_kit.doctrine.entity_manager "blog"');
        $this->load(['doctrine' => ['private_entity_manager' => true, 'user_reference' => 'id', 'entity_manager' => 'blog']]);
    }

    #[Test]
    public function thePassUsesYourDefaultManagerAndTagsTheMiddlewareForItsConnection(): void
    {
        $container = $this->load(['doctrine' => ['private_entity_manager' => true]]);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setParameter('doctrine.entity_managers', ['main' => 'doctrine.orm.main_entity_manager']);
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition(null, [new Reference('doctrine.dbal.app_connection')]));

        new BlogKitPrivateEntityManagerPass()->process($container);

        self::assertSame('main', $container->getParameter(NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'app']], $container->getDefinition(NowoBlogKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    #[Test]
    public function thePassFailsOnAnUnknownDefaultManagerAndDoesNothingOutsideThePrivateMode(): void
    {
        $host = $this->deprecatedLoad();
        new BlogKitPrivateEntityManagerPass()->process($host);
        self::assertSame('', $host->getParameter(NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));

        $container = $this->load(['doctrine' => ['private_entity_manager' => true]]);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setParameter('doctrine.entity_managers', ['other' => 'doctrine.orm.other_entity_manager']);

        $this->expectException(InvalidConfigurationException::class);
        new BlogKitPrivateEntityManagerPass()->process($container);
    }

    private function deprecatedLoad(): ContainerBuilder
    {
        $container = null;
        self::deprecationsOf(function () use (&$container): void {
            $container = $this->load([]);
        });
        self::assertInstanceOf(ContainerBuilder::class, $container);

        return $container;
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
}
