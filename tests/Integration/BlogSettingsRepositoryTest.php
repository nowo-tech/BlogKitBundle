<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use PHPUnit\Framework\Attributes\Test;

final class BlogSettingsRepositoryTest extends DoctrineTestCase
{
    private BlogSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BlogSettingsRepository($this->registry);
    }

    #[Test]
    public function findSingletonReturnsNullWhenNoRowExists(): void
    {
        self::assertNull($this->repository->findSingleton());
        self::assertNull($this->repository->findSingleton());
    }

    #[Test]
    public function findSingletonReturnsExistingPersistedSettingsWithoutCreatingAnotherRow(): void
    {
        $existing        = $this->createSettings();
        $freshRepository = new BlogSettingsRepository($this->registry);

        $settings = $freshRepository->findSingleton();

        self::assertSame($existing->getId(), $settings?->getId());
        self::assertSame(1, $freshRepository->count([]));
    }

    #[Test]
    public function getSingletonCreatesAndCachesTheSettingsEntity(): void
    {
        $first  = $this->repository->getSingleton();
        $second = $this->repository->getSingleton();

        self::assertSame($first, $second);
        self::assertNotNull($first->getId());
        self::assertSame(1, $this->repository->count([]));
    }

    #[Test]
    public function resetClearsTheCachedSingletonAndReloadsTheExistingRow(): void
    {
        $first = $this->repository->getSingleton();

        $this->repository->reset();
        $this->clearEntityManager();

        $reloaded = $this->repository->getSingleton();

        self::assertNotSame($first, $reloaded);
        self::assertSame($first->getId(), $reloaded->getId());
        self::assertSame(1, $this->repository->count([]));
    }

    #[Test]
    public function getSingletonReturnsExistingPersistedSettingsInsteadOfCreatingAnotherRow(): void
    {
        $existing        = $this->createSettings();
        $freshRepository = new BlogSettingsRepository($this->registry);

        $settings = $freshRepository->getSingleton();

        self::assertSame($existing->getId(), $settings->getId());
        self::assertSame(1, $freshRepository->count([]));
    }

    #[Test]
    public function getSingletonCreatesTheRowWithoutFlushingPendingHostChanges(): void
    {
        $existing = $this->createUser('existing@example.test');
        $existing->setEmail('changed@example.test');
        $pending = new MappedTestUser()->setEmail('pending@example.test');
        $this->entityManager->persist($pending);

        $settings = $this->repository->getSingleton();

        self::assertNotNull($settings->getId());
        self::assertSame(6, $settings->getPerPage());
        self::assertSame(1, $this->repository->count([]));
        self::assertSame(
            ['existing@example.test'],
            $this->connection->fetchFirstColumn('SELECT email FROM blog_kit_test_user'),
            'Pending host changes must not be flushed by a read.',
        );

        $unitOfWork = $this->entityManager->getUnitOfWork();
        self::assertTrue($unitOfWork->isScheduledForInsert($pending));

        $this->entityManager->flush();

        self::assertSame(
            ['changed@example.test', 'pending@example.test'],
            $this->connection->fetchFirstColumn('SELECT email FROM blog_kit_test_user ORDER BY email'),
        );
    }

    #[Test]
    public function getSingletonInsertsTheEntityDefaults(): void
    {
        $settings = $this->repository->getSingleton();
        $defaults = new BlogSettings();

        self::assertSame($defaults->toPublicArray(), $settings->toPublicArray());
    }

    #[Test]
    public function getSingletonPassesTheIdOfAPreInsertGenerator(): void
    {
        $metadata              = $this->entityManager->getClassMetadata(BlogSettings::class);
        $metadata->idGenerator = new class extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): int
            {
                return 42;
            }
        };

        self::assertSame(42, $this->repository->getSingleton()->getId());
    }
}
