<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Support;

use Nowo\BlogKitBundle\Support\BlogUserIdResolver;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BlogUserIdResolverTest extends TestCase
{
    #[Test]
    public function idOfReturnsStringIdWhenPresent(): void
    {
        $user = (new TestUser())->setId(42);

        self::assertSame('42', BlogUserIdResolver::idOf($user));
    }

    #[Test]
    public function idOfReturnsNullForMissingOrEmptyIds(): void
    {
        self::assertNull(BlogUserIdResolver::idOf(null));
        self::assertNull(BlogUserIdResolver::idOf(new stdClass()));
        self::assertNull(BlogUserIdResolver::idOf((new TestUser())->setId(null)));
    }

    #[Test]
    public function isSameComparesNumericIdsThenIdentifiers(): void
    {
        $alice              = (new TestUser())->setId(1)->setEmail('alice@example.test');
        $clone              = (new TestUser())->setId(1)->setEmail('other@example.test');
        $bob                = (new TestUser())->setId(2)->setEmail('bob@example.test');
        $sameEmailWithoutId = (new TestUser())->setId(null)->setEmail('alice@example.test');
        $aliceWithoutId     = (new TestUser())->setId(null)->setEmail('alice@example.test');

        self::assertTrue(BlogUserIdResolver::isSame($alice, $clone));
        self::assertFalse(BlogUserIdResolver::isSame($alice, $bob));
        self::assertFalse(BlogUserIdResolver::isSame($alice, null));
        self::assertTrue(BlogUserIdResolver::isSame($aliceWithoutId, $sameEmailWithoutId));
        self::assertFalse(BlogUserIdResolver::isSame($aliceWithoutId, (new TestUser())->setId(null)->setEmail('')));
        self::assertFalse(BlogUserIdResolver::isSame(new stdClass(), new stdClass()));
        $emptyId = new class {
            public function getId(): string
            {
                return '';
            }
        };
        self::assertNull(BlogUserIdResolver::idOf($emptyId));
    }
}
