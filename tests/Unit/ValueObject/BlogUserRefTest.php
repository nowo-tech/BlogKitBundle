<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

use function str_repeat;

/**
 * REQ-SEC-006 items 1, 5 and 7: an id that cannot be kept exactly is refused; look-alikes stay different ids.
 */
final class BlogUserRefTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableIds(): iterable
    {
        yield 'empty' => [''];
        yield 'leading space' => [' bob'];
        yield 'trailing space' => ['bob '];
        yield 'trailing newline' => ["bob\n"];
        yield 'nbsp' => ["bob\u{00A0}"];
        yield 'zwsp inside' => ["b\u{200B}ob"];
        yield 'bom' => ["\u{FEFF}bob"];
        yield 'bidi mark' => ["bob\u{200F}x"];
        yield 'control char' => ["bo\x07b"];
        yield 'invalid utf-8' => ["bob\xC3"];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[Test]
    #[DataProvider('unusableIds')]
    public function unusableIdsAreRefused(string $id): void
    {
        self::assertFalse(BlogUserRef::isValid($id));
        $this->expectException(InvalidArgumentException::class);
        new BlogUserRef($id);
    }

    #[Test]
    public function usableIdsAreKeptExactly(): void
    {
        foreach (['bob', 'Bob', "b\u{00F3}b", "bo\u{0301}b", str_repeat('a', 128), '42', 'a b'] as $id) {
            $ref = new BlogUserRef($id);
            self::assertSame($id, $ref->id);
            self::assertSame($id, $ref->getId());
            self::assertSame($id, $ref->getUserIdentifier());
            self::assertSame($id, (string) $ref);
        }

        // NFC and NFD are different ids (item 7).
        self::assertFalse(new BlogUserRef("b\u{00F3}b")->equals(new BlogUserRef("bo\u{0301}b")));
        self::assertFalse(new BlogUserRef('bob')->equals(new BlogUserRef('Bob')));
        self::assertTrue(new BlogUserRef('bob')->equals(new PlainHostUser('bob')));
        self::assertFalse(new BlogUserRef('bob')->equals(null));
    }

    #[Test]
    public function idOfReadsGetIdOfAnyHostUser(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'uuid-1';
            }
        };

        self::assertSame('7', BlogUserRef::idOf(new PlainHostUser(7)));
        self::assertSame('bob', BlogUserRef::idOf(new PlainHostUser('bob')));
        self::assertSame('uuid-1', BlogUserRef::idOf(new PlainHostUser($stringable)));
        self::assertSame('ref', BlogUserRef::idOf(new BlogUserRef('ref')));
        self::assertNull(BlogUserRef::idOf(null));
        self::assertNull(BlogUserRef::idOf(new stdClass()));
        self::assertNull(BlogUserRef::idOf(new PlainHostUser('')));
        self::assertNull(BlogUserRef::idOf(new PlainHostUser(null)));
        self::assertNull(BlogUserRef::idOf(new PlainHostUser(1.5)));
    }

    #[Test]
    public function tryFromUserFailsClosed(): void
    {
        $ref = new BlogUserRef('bob');

        self::assertSame($ref, BlogUserRef::tryFromUser($ref));
        self::assertEquals(new BlogUserRef('42'), BlogUserRef::tryFromUser(new PlainHostUser(42)));
        self::assertNull(BlogUserRef::tryFromUser(new PlainHostUser('bob ')));
        self::assertNull(BlogUserRef::tryFromUser(null));
    }
}
