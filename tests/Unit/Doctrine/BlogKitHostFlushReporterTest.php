<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Doctrine\BlogKitHostFlushReporter;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Tests\Integration\Worker\Entity\HostNote;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\Tests\Support\RecordingLogger;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;

use const CASE_LOWER;

/**
 * Since 1.12.0: the host-flush probe of the host mode, on a real unit of work (SQLite in memory).
 */
#[CoversClass(BlogKitHostFlushReporter::class)]
final class BlogKitHostFlushReporterTest extends TestCase
{
    use CollectsDeprecations;

    private const string MESSAGE = 'Your pending changes to ' . HostNote::class . ' were flushed by NowoBlogKitBundle; 2.0 no longer does.';

    #[Test]
    public function aForeignPendingEntityIsReportedOncePerClassAndUnitOfWork(): void
    {
        $manager  = $this->manager();
        $reporter = new BlogKitHostFlushReporter(true);
        $manager->persist(new HostNote('first'));
        $manager->persist($this->article('own'));

        self::assertSame([self::MESSAGE], self::deprecationsOf(static fn () => $reporter->beforeFlush($manager)), 'the bundle\'s own entity is never reported');

        $manager->persist(new HostNote('second'));
        self::assertSame([], self::deprecationsOf(static fn () => $reporter->beforeFlush($manager)), 'once per class and unit of work');

        $reporter->reset();
        self::assertSame([self::MESSAGE], self::deprecationsOf(static fn () => $reporter->beforeFlush($manager)), 'a new unit of work reports again');
        self::assertCount(3, $manager->getUnitOfWork()->getScheduledEntityInsertions(), 'read-only: nothing flushed nor cleared');
    }

    #[Test]
    public function onlyTheBundlesOwnPendingWorkReportsNothing(): void
    {
        $manager = $this->manager();
        $manager->persist($this->article('own'));

        self::assertSame([], self::deprecationsOf(static fn () => new BlogKitHostFlushReporter(true)->beforeFlush($manager)));
    }

    #[Test]
    public function disabledInspectsNothing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getUnitOfWork');

        $reporter = new BlogKitHostFlushReporter();
        self::assertFalse($reporter->isEnabled());
        $reporter->beforeFlush($entityManager);
        $reporter->reset();
    }

    #[Test]
    public function thePrivateManagerIsNeverInspected(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManager');

        new BlogKitHostFlushReporter(true)->beforeFlush(new PrivateEntityManager($registry));
    }

    #[Test]
    public function aFailingProbeNeverBreaksTheWrite(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willThrowException(new RuntimeException('broken'));
        $logger = new RecordingLogger();

        new BlogKitHostFlushReporter(true, null, $logger)->beforeFlush($entityManager);

        self::assertNotSame([], $logger->records, 'the failure is logged');
    }

    private function article(string $slug): BlogArticle
    {
        return new BlogArticle()->setSlug($slug)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
    }

    private function manager(): EntityManager
    {
        $root          = dirname(__DIR__, 3);
        $configuration = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity', $root . '/tests/Integration/Worker/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        $manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        new SchemaTool($manager)->createSchema([$manager->getClassMetadata(HostNote::class)]);

        return $manager;
    }
}
