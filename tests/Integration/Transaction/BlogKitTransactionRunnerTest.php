<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Transaction;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use LogicException;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticlePublishedDoctrineSubscriber;
use Nowo\BlogKitBundle\Tests\Integration\DoctrineTestCase;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.10.0 (REQ-RUNTIME-003 rules 1 to 3): the bundle's runner on a real (SQLite) manager.
 */
final class BlogKitTransactionRunnerTest extends DoctrineTestCase
{
    /** @var list<array{slug: string, inTransaction: bool}> */
    private array $published = [];

    private BlogArticlePublishedDoctrineSubscriber $publishedEvents;

    protected function setUp(): void
    {
        parent::setUp();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BlogArticlePublishedEvent::class, function (BlogArticlePublishedEvent $event): void {
            $this->published[] = ['slug' => $event->getArticleSnapshot()->slug, 'inTransaction' => $this->connection->isTransactionActive()];
        });
        $this->publishedEvents = new BlogArticlePublishedDoctrineSubscriber($dispatcher);
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush, Events::postFlush], $this->publishedEvents);
    }

    #[Test]
    public function aUnitFlushesAndCommitsAndAnnouncesAfterTheCommit(): void
    {
        $result = $this->runner()->run(function (): string {
            $this->entityManager->persist(new BlogArticle()->setSlug('one')->setPublished(true));

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM content_blog_article WHERE slug = 'one'"));
        self::assertSame([['slug' => 'one', 'inTransaction' => false]], $this->published);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * Built directly on a caller's manager (no manager name: never the bundle's own wiring since 2.0), the runner treats
     * it as the caller's: it refuses to start a unit over pending changes rather than flush them.
     */
    #[Test]
    public function aRunnerBuiltDirectlyOnACallersManagerRefusesItsPendingChanges(): void
    {
        $this->entityManager->persist(new BlogTag()->setSlug('pending'));

        $this->expectException(LogicException::class);
        $this->runner()->run(static fn (): null => null);
    }

    #[Test]
    public function aUnitOnTheBundlesOwnManagerUsesTheOwnManagerRunner(): void
    {
        $runner = new BlogKitTransactionRunner($this->entityManager, $this->registry, 'blog', $this->publishedEvents);

        $runner->run(function (): void {
            $this->entityManager->persist(new BlogTag()->setSlug('own'));
        });

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM content_blog_tag WHERE slug = 'own'"));
    }

    #[Test]
    public function aUniqueViolationRunsTheUnitOnceMoreAndASecondOneIsRethrown(): void
    {
        $attempts = 0;
        $this->runner()->runRereadingOnDuplicate(function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw $this->uniqueViolation();
            }

            $this->entityManager->persist(new BlogTag()->setSlug('second'));
        });

        self::assertSame(2, $attempts);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM content_blog_tag WHERE slug = 'second'"));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->runner()->runRereadingOnDuplicate(fn (): never => throw $this->uniqueViolation());
    }

    #[Test]
    public function anyOtherFailureIsRethrownWithoutAReplay(): void
    {
        $attempts = 0;

        try {
            $this->runner()->runRereadingOnDuplicate(static function () use (&$attempts): never {
                ++$attempts;

                throw new RuntimeException('domain');
            });
            self::fail('The failure must leave the runner.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('domain', $runtimeException->getMessage());
        }

        self::assertSame(1, $attempts);
    }

    #[Test]
    public function flushPendingSavesTheBoundChangesInOneTransaction(): void
    {
        $article         = $this->createArticle('draft', published: false);
        $this->published = [];
        $article->setPublished(true);

        $this->runner()->flushPending();

        self::assertSame([['slug' => 'draft', 'inTransaction' => false]], $this->published);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function aLockConflictOfTheFlushLeavesAsTransientAndIsNeverAnnounced(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->connection);
        $manager->method('flush')->willReturnCallback(function (): never {
            // The real flush runs inside the unit's transaction (its event is held), then the lock conflict.
            $this->entityManager->persist(new BlogArticle()->setSlug('rolled-back')->setPublished(true));
            $this->entityManager->flush();

            throw new DeadlockException($this->driverException('40001'), null);
        });
        $runner = new BlogKitTransactionRunner($manager, publishedEvents: $this->publishedEvents);

        try {
            $runner->flushPending();
            self::fail('A deadlock must leave the runner.');
        } catch (TransientStorageFailure $failure) {
            self::assertInstanceOf(DeadlockException::class, $failure->getPrevious());
            self::assertSame(1, $failure->retryAfterSeconds());
        }

        self::assertSame([], $this->published);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM content_blog_article WHERE slug = 'rolled-back'"));
    }

    #[Test]
    public function theUnmanagedRunnerKeepsThe19BareFlush(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::exactly(2))->method('flush');
        $runner = BlogKitTransactionRunner::unmanaged($manager);

        self::assertSame(7, $runner->run(static fn (): int => 7));
        $runner->flushPending();
    }

    #[Test]
    public function withoutARegistryOrAKnownNameTheCallersManagerHasNoName(): void
    {
        new BlogKitTransactionRunner($this->entityManager)->run(function (): void {
            $this->entityManager->persist(new BlogTag()->setSlug('no-registry'));
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManagerNames')->willReturn(['other' => 'doctrine.orm.other_entity_manager']);
        $registry->method('getManager')->willReturn($this->createStub(EntityManagerInterface::class));
        new BlogKitTransactionRunner($this->entityManager, $registry)->run(function (): void {
            $this->entityManager->persist(new BlogTag()->setSlug('unknown-name'));
        });

        self::assertSame(['no-registry', 'unknown-name'], $this->connection->fetchFirstColumn("SELECT slug FROM content_blog_tag WHERE slug IN ('no-registry', 'unknown-name') ORDER BY slug"));
    }

    /** Since 2.0 the 1.12 host-flush reporter argument is accepted and ignored (deprecated; removed in 3.0). */
    #[Test]
    public function theHostFlushReporterArgumentIsIgnored(): void
    {
        $runner = new BlogKitTransactionRunner($this->entityManager, $this->registry, 'blog', $this->publishedEvents, hostFlushReporter: new stdClass());
        $runner->run(function (): void {
            $this->entityManager->persist(new BlogTag()->setSlug('ignored-reporter'));
        });

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM content_blog_tag WHERE slug = 'ignored-reporter'"));
    }

    private function runner(): BlogKitTransactionRunner
    {
        return new BlogKitTransactionRunner($this->entityManager, $this->registry, null, $this->publishedEvents);
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException($this->driverException('23000'), null);
    }

    private function driverException(string $sqlState): DriverException
    {
        return new class('driver', $sqlState) extends Exception implements DriverException {
            public function __construct(string $message, private readonly string $sqlState)
            {
                parent::__construct($message);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }
}
