<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\StringType;
use InvalidArgumentException;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\BlogKitBundle\ValueObject\UnreadableBlogUserRef;

use function is_object;
use function is_string;

/**
 * Since 1.9.0 (`doctrine.user_reference: id`): the column type of the user fields of the blog rows. The database holds
 * the user's id as a string; PHP reads it back as a {@see BlogUserRef}. Ids that cannot be kept exactly are refused
 * before any write (REQ-SEC-006 item 1), on every platform; a stored one reads back as an {@see UnreadableBlogUserRef}
 * that every owner check denies.
 *
 * @internal registered by the bundle (`doctrine.dbal.types`)
 */
final class BlogUserRefType extends StringType
{
    public const string NAME = 'nowo_blog_kit_user_ref';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        // Audit ON-M1: an unreadable stored value is written back exactly as it was read (never as NULL).
        if ($value instanceof UnreadableBlogUserRef) {
            return $value->raw;
        }

        $id = match (true) {
            is_string($value) => $value,
            is_object($value) => BlogUserRef::idOf($value),
            default           => null,
        };
        if ($id === null) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', BlogUserRef::class, 'an object with getId()']);
        }

        if (!BlogUserRef::isValid($id)) {
            throw new InvalidArgumentException('A BlogKit user reference is a valid UTF-8 id of 1 to 128 characters, without whitespace at its edges and without control or format characters.');
        }

        return $id;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): BlogUserRef|UnreadableBlogUserRef|null
    {
        if ($value === null) {
            return null;
        }

        $id = (string) $value;

        // Audit ON-M1: a stored value that cannot be kept exactly is an owner nobody matches (fail closed), never "no
        // owner" and never trimmed into another id. Only a real NULL is "no user".
        return BlogUserRef::isValid($id) ? new BlogUserRef($id) : new UnreadableBlogUserRef($id);
    }
}
