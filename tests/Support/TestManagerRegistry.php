<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

use function in_array;

/**
 * Minimal ManagerRegistry for ServiceEntityRepository constructors and SQLite integration tests.
 */
final class TestManagerRegistry implements ManagerRegistry
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Connection $connection,
        /** @var list<class-string> */
        private readonly array $managedClasses = [],
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

    public function getManagerForClass(string $class): ?ObjectManager
    {
        if ($this->managedClasses === [] || in_array($class, $this->managedClasses, true)) {
            return $this->entityManager;
        }

        return null;
    }
}
