<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use InvalidArgumentException;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BlogUserRefTypeTest extends TestCase
{
    #[Test]
    public function convertsBothWays(): void
    {
        $type     = new BlogUserRefType();
        $platform = new SQLitePlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertSame('bob', $type->convertToDatabaseValue('bob', $platform));
        self::assertSame('bob', $type->convertToDatabaseValue(new BlogUserRef('bob'), $platform));
        self::assertSame('7', $type->convertToDatabaseValue(new PlainHostUser(7), $platform));

        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertNull($type->convertToPHPValue('', $platform));
        self::assertEquals(new BlogUserRef('Bob'), $type->convertToPHPValue('Bob', $platform));
        self::assertNull($type->convertToPHPValue('bob ', $platform), 'a legacy value that cannot be kept exactly is unreachable');
    }

    #[Test]
    public function refusesValuesWithoutAnId(): void
    {
        $this->expectException(InvalidType::class);
        new BlogUserRefType()->convertToDatabaseValue(new stdClass(), new SQLitePlatform());
    }

    #[Test]
    public function refusesScalarsOtherThanStrings(): void
    {
        $this->expectException(InvalidType::class);
        new BlogUserRefType()->convertToDatabaseValue(42, new SQLitePlatform());
    }

    #[Test]
    public function refusesIdsThatCannotBeKeptExactly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BlogUserRefType()->convertToDatabaseValue("bob\u{200B}", new SQLitePlatform());
    }
}
