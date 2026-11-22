<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\Events;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitPrivateEntityManagerPass;
use Nowo\BlogKitBundle\DependencyInjection\Configuration;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function class_exists;
use function defined;

/**
 * Since 2.0 (REQ-ARCH-001 rule 6): the private entity manager is the only mode without `doctrine.entity_manager`; the
 * setting table of UPGRADING "To 2.0.0" row by row, at the extension level (the kernel tests cover the writes).
 */
final class PrivateEntityManagerOptionTest extends TestCase
{
    use CollectsDeprecations;

    /** The notice of `doctrine.private_entity_manager` (any value Symfony accepts) since 2.0. */
    private const string TRUE_NOTICE = 'Since nowo-tech/blog-kit-bundle 2.0: The "private_entity_manager" option at "nowo_blog_kit.doctrine" has no effect since 2.0 (without doctrine.entity_manager the private entity manager is the only mode): remove it. The key is removed in 3.0.';

    /**
     * The 2.0 setting table, row "neither option" (the 1.x default, where the bundle flushed your default manager):
     * the private entity manager, without any notice.
     */
    #[Test]
    public function unsetWiresThePrivateManagerWithoutANotice(): void
    {
        $container = null;
        self::assertSame([], self::deprecationsOf(function () use (&$container): void {
            $container = $this->load([]);
        }));
        self::assertInstanceOf(ContainerBuilder::class, $container);

        self::assertNull($container->getParameter(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        $this->assertPrivateWiring($container);
    }

    /** Row "true": the same wiring; the key is a deprecated no-op (removed in 3.0). */
    #[Test]
    public function trueIsADeprecatedNoOp(): void
    {
        $container = null;
        self::assertSame([self::TRUE_NOTICE], self::deprecationsOf(function () use (&$container): void {
            $container = $this->load(['doctrine' => ['private_entity_manager' => true]]);
        }));
        self::assertInstanceOf(ContainerBuilder::class, $container);

        self::assertTrue($container->getParameter(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        $this->assertPrivateWiring($container);
    }

    /** Row "false": the 1.x mode that flushed your manager was removed, so the build fails and says what to do. */
    #[Test]
    public function falseFailsTheBuild(): void
    {
        foreach ([
            [[], 'nowo_blog_kit.doctrine.private_entity_manager: false is no longer supported: 2.0 removed the mode in which blog writes flush your entity manager. Remove the key. The bundle then writes through its private entity manager (same connection and tables), which never flushes your pending changes; or set doctrine.entity_manager to a dedicated manager. See UPGRADING.md "To 2.0.0".'],
            [['user_reference' => 'id', 'entity_manager' => 'blog'], 'nowo_blog_kit.doctrine.private_entity_manager: false is no longer supported: 2.0 removed the mode in which blog writes flush your entity manager. Remove the key. doctrine.entity_manager "blog" stays: your dedicated manager keeps working as in 1.x. See UPGRADING.md "To 2.0.0".'],
        ] as [$doctrine, $message]) {
            try {
                self::deprecationsOf(fn (): ContainerBuilder => $this->load(['doctrine' => ['private_entity_manager' => false] + $doctrine]));
                self::fail('false must fail the build');
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        self::assertSame(
            'nowo_blog_kit.doctrine.private_entity_manager: false is no longer supported: 2.0 removed the mode in which blog writes flush your entity manager. Remove the key. The bundle then writes through its private entity manager (same connection and tables), which never flushes your pending changes; or set doctrine.entity_manager to a dedicated manager. See UPGRADING.md "To 2.0.0".',
            Configuration::privateEntityManagerFalseMessage(''),
        );
    }

    /** Row "entity_manager: <dedicated>": unchanged (the DDD option), no notice, no private manager. */
    #[Test]
    public function aDedicatedManagerIsUnchanged(): void
    {
        $container = null;
        self::assertSame([], self::deprecationsOf(function () use (&$container): void {
            $container = $this->load(['doctrine' => ['user_reference' => 'id', 'entity_manager' => 'blog']]);
        }));
        self::assertInstanceOf(ContainerBuilder::class, $container);

        self::assertFalse($container->hasDefinition(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        self::assertFalse($container->hasDefinition(NowoBlogKitExtension::PRIVATE_MIDDLEWARE_SERVICE));
        self::assertSame('doctrine.orm.blog_entity_manager', (string) $container->getAlias(NowoBlogKitExtension::ENTITY_MANAGER_SERVICE));
        self::assertSame('blog', $container->getParameter(NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER));
    }

    /** The removed host-mode classes are gone with the mode. */
    #[Test]
    public function theHostModeClassesAreGone(): void
    {
        self::assertFalse(class_exists('Nowo\\BlogKitBundle\\Doctrine\\BlogKitHostFlushReporter'));
        self::assertFalse(class_exists('Nowo\\BlogKitBundle\\Doctrine\\BlogKitEntityManagerFactory'));
        self::assertFalse(defined(NowoBlogKitExtension::class . '::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION'));
        self::assertFalse(defined(NowoBlogKitExtension::class . '::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION'));
    }

    private function assertPrivateWiring(ContainerBuilder $container): void
    {
        $manager = $container->getDefinition(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertSame(PrivateEntityManager::class, $manager->getClass());
        self::assertTrue($manager->hasTag(PrivateEntityManager::TAG));
        self::assertTrue($manager->hasTag('kernel.reset'));
        self::assertSame('%' . NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER . '%', $manager->getArgument(1));
        self::assertSame(NowoBlogKitExtension::BUNDLE_NAME, $manager->getArgument(3));
        self::assertSame(NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE, (string) $container->getAlias(NowoBlogKitExtension::ENTITY_MANAGER_SERVICE));

        $registry = new Reference(NowoBlogKitExtension::PRIVATE_REGISTRY_SERVICE);
        self::assertSame(PrivateManagerRegistry::class, $container->getDefinition(NowoBlogKitExtension::PRIVATE_REGISTRY_SERVICE)->getClass());
        self::assertSame(['Nowo\\BlogKitBundle\\Entity'], $container->getDefinition(NowoBlogKitExtension::PRIVATE_REGISTRY_SERVICE)->getArgument(2));
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
            [NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE],
            array_map(static fn (Reference $reference): string => (string) $reference, $resettables->getValues()),
            'the private manager is dropped at every boundary',
        );
        self::assertSame(ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE, $resettables->getValues()[0]->getInvalidBehavior(), 'a boundary never builds it');
    }

    #[Test]
    public function theIdModeNeedsNoUserReferenceListener(): void
    {
        $container = $this->load(['doctrine' => ['user_reference' => 'id']]);

        self::assertFalse($container->hasDefinition(BlogKitPrivateUserReferenceListener::class));
    }

    #[Test]
    public function trueCannotBeCombinedWithADedicatedManager(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be combined with nowo_blog_kit.doctrine.entity_manager "blog"');
        self::deprecationsOf(fn (): ContainerBuilder => $this->load(['doctrine' => ['private_entity_manager' => true, 'user_reference' => 'id', 'entity_manager' => 'blog']]));
    }

    #[Test]
    public function thePassUsesYourDefaultManagerAndTagsTheMiddlewareForItsConnection(): void
    {
        $container = $this->load([]);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setParameter('doctrine.entity_managers', ['main' => 'doctrine.orm.main_entity_manager']);
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition(null, [new Reference('doctrine.dbal.app_connection')]));

        new BlogKitPrivateEntityManagerPass()->process($container);

        self::assertSame('main', $container->getParameter(NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'app']], $container->getDefinition(NowoBlogKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    #[Test]
    public function thePassFailsOnAnUnknownDefaultManagerAndDoesNothingWithADedicatedManager(): void
    {
        $dedicated = $this->load(['doctrine' => ['user_reference' => 'id', 'entity_manager' => 'blog']]);
        new BlogKitPrivateEntityManagerPass()->process($dedicated);
        self::assertSame('', $dedicated->getParameter(NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));

        $container = $this->load([]);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setParameter('doctrine.entity_managers', ['other' => 'doctrine.orm.other_entity_manager']);

        $this->expectException(InvalidConfigurationException::class);
        new BlogKitPrivateEntityManagerPass()->process($container);
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
