<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\BlogKitBundle\Doctrine\BlogKitEntityManagerFactory;
use Nowo\BlogKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogKitEntityManagerFactoryTest extends TestCase
{
    #[Test]
    public function returnsTheManagerThatMapsTheBlog(): void
    {
        $manager  = $this->createStub(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManagerForClass')->with(BlogArticle::class)->willReturn($manager);

        self::assertSame($manager, BlogKitEntityManagerFactory::create($registry));
    }

    #[Test]
    public function failsWithoutAnOrmManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        BlogKitEntityManagerFactory::create($registry);
    }

    #[Test]
    public function excludedDriverMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();
        self::assertTrue($driver->isTransient(BlogArticle::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $driver->loadMetadataForClass(BlogArticle::class, new ClassMetadata(BlogArticle::class));
    }
}
