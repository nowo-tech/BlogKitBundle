<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use InvalidArgumentException;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\BlogKitBundle\ValueObject\UnreadableBlogUserRef;
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
        self::assertEquals(new BlogUserRef('Bob'), $type->convertToPHPValue('Bob', $platform));
        // Audit ON-M1: a stored value that cannot be kept exactly is an owner nobody matches, never "no user".
        self::assertEquals(new UnreadableBlogUserRef('bob '), $type->convertToPHPValue('bob ', $platform));
        self::assertEquals(new UnreadableBlogUserRef(''), $type->convertToPHPValue('', $platform));
        self::assertSame('bob ', $type->convertToDatabaseValue(new UnreadableBlogUserRef('bob '), $platform), 'written back unchanged');
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
