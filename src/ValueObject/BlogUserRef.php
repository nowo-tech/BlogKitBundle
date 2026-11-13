<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

use InvalidArgumentException;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Stringable;

use function is_int;
use function is_string;
use function mb_strlen;
use function method_exists;
use function preg_match;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 2, `doctrine.user_reference: id`): a host user referenced by id.
 *
 * With the id mode the blog rows keep the user's id in a string column instead of a Doctrine relation to `user_class`,
 * and a row read back holds this value in its user field (`createdBy`, `updatedBy`, `staffAuthor`, `moderatedBy`). It
 * implements {@see BlogUserInterface} so the entity accessors keep their types; the host user class itself never has
 * to. The id is exact (REQ-SEC-006): never trimmed, case-folded or normalised; an id the bundle cannot keep exactly is
 * refused ({@see self::isValid()}).
 */
final readonly class BlogUserRef implements BlogUserInterface, Stringable
{
    public const int MAX_LENGTH = 128;

    public function __construct(public string $id)
    {
        if (!self::isValid($id)) {
            throw new InvalidArgumentException('A BlogKit user reference is a valid UTF-8 id of 1 to 128 characters, without whitespace at its edges and without control or format characters.');
        }
    }

    /**
     * Whether an id can be kept exactly. Refused: empty, more than 128 characters, invalid UTF-8, whitespace (Unicode,
     * NBSP included) at either edge, and control or format characters anywhere (ZWSP, BOM, bidi marks...).
     */
    public static function isValid(string $id): bool
    {
        if ($id === '' || preg_match('//u', $id) !== 1 || mb_strlen($id) > self::MAX_LENGTH) {
            return false;
        }

        return preg_match('/^[\s\p{Z}]|[\s\p{Z}]$|\p{C}/uD', $id) !== 1;
    }

    /**
     * The id a host user exposes through `getId()` (an int, a non-empty string or a `Stringable` such as a Symfony
     * `Uuid`), as a string; null without a user, without `getId()`, or for any other value.
     */
    public static function idOf(?object $user): ?string
    {
        if ($user instanceof self) {
            return $user->id;
        }

        if ($user === null || !method_exists($user, 'getId')) {
            return null;
        }

        $id = $user->getId();
        if (is_int($id)) {
            return (string) $id;
        }

        $id = $id instanceof Stringable ? (string) $id : $id;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The reference of a host user (or of a reference), or null when it has no id that can be kept exactly.
     */
    public static function tryFromUser(?object $user): ?self
    {
        if ($user instanceof self) {
            return $user;
        }

        $id = self::idOf($user);

        return $id !== null && self::isValid($id) ? new self($id) : null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** The id: a reference knows nothing else about the user (labels come from `user_directory`). */
    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    public function equals(?object $other): bool
    {
        return $other !== null && self::idOf($other) === $this->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
