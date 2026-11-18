<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use ReflectionClass;

/**
 * A ManagerRegistry whose resetManager() replaces the manager IN PLACE, as DoctrineBundle does with its lazy managers:
 * the object every service holds is turned back into a lazy ghost that builds a fresh, open manager on first use.
 * The real-database tests use it to replay a unit after a failed flush closed the manager.
 */
final class ResettingManagerRegistry implements ManagerRegistry
{
    public int $resets = 0;

    /**
     * @param Closure(EntityManager): void $initializer calls the manager's constructor on the ghost
     */
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Connection $connection,
        private readonly Closure $initializer,
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): Connection
    {
        return $this->connection;
    }

    public function getConnections(): array
    {
        return ['default' => $this->connection];
    }

    public function getConnectionNames(): array
    {
        return ['default' => 'default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->entityManager;
    }

    public function getManagers(): array
    {
        return ['default' => $this->entityManager];
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        ++$this->resets;
        new ReflectionClass(EntityManager::class)->resetAsLazyGhost($this->entityManager, $this->initializer);

        return $this->entityManager;
    }

    public function getManagerNames(): array
    {
        return ['default' => 'default'];
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->entityManager->getRepository($persistentObject);
    }

    public function getManagerForClass(string $class): ObjectManager
    {
        return $this->entityManager;
    }
}
