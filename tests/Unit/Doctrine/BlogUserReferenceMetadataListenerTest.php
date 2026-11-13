<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Nowo\BlogKitBundle\Doctrine\BlogUserReferenceMetadataListener;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;

/**
 * REQ-SEC-006 items 3 and 8: binary NO PAD collation per platform; a server version without a patch number, or older
 * than 8.0.17, falls back to utf8mb4_bin with a logged warning.
 */
final class BlogUserReferenceMetadataListenerTest extends TestCase
{
    #[Test]
    public function collationPerPlatformAndServerVersion(): void
    {
        $logger   = new RecordingLogger();
        $listener = new BlogUserReferenceMetadataListener($logger);

        self::assertNull($listener->collation(new SQLitePlatform()));
        self::assertNull($listener->collation(new PostgreSQLPlatform()));
        self::assertSame('utf8mb4_nopad_bin', $listener->collation(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', $listener->collation(new MySQL80Platform(), $this->connection('8.0.36')));
        self::assertSame('utf8mb4_0900_bin', $listener->collation(new MySQL80Platform(), $this->connection('8.4.2-log')));
        self::assertSame([], $logger->records);

        self::assertSame('utf8mb4_bin', $listener->collation(new MySQL80Platform(), $this->connection('8.0.16')));
        self::assertSame('utf8mb4_bin', $listener->collation(new MySQLPlatform(), $this->connection('8.0')));
        self::assertSame('utf8mb4_bin', $listener->collation(new MySQLPlatform()));
        $failing = $this->createStub(Connection::class);
        $failing->method('getServerVersion')->willThrowException(new RuntimeException('down'));
        self::assertSame('utf8mb4_bin', $listener->collation(new MySQLPlatform(), $failing));
        self::assertCount(4, $logger->records);
    }

    #[Test]
    public function otherEntitiesAreLeftAlone(): void
    {
        $metadata = new ClassMetadata(BlogTag::class);
        $manager  = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getConnection');

        new BlogUserReferenceMetadataListener()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));
        self::assertSame([], $metadata->fieldMappings);
    }

    #[Test]
    public function mysqlColumnsGetTheBinaryCollationAndAnIndex(): void
    {
        $metadata = new ClassMetadata(BlogArticle::class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity'])->loadMetadataForClass(BlogArticle::class, $metadata);
        $connection = $this->connection('8.0.36');
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        new BlogUserReferenceMetadataListener()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));

        self::assertSame(['collation' => 'utf8mb4_0900_bin'], $metadata->getFieldMapping('createdBy')->options);
        self::assertSame('created_by_id', $metadata->getColumnName('createdBy'));
        self::assertContains(['columns' => ['created_by_id']], $metadata->table['indexes']);
    }

    /**
     * @return Connection&Stub
     */
    private function connection(string $version): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getServerVersion')->willReturn($version);

        return $connection;
    }
}
