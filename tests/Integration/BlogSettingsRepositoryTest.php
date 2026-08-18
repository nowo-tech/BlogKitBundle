<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
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
}
