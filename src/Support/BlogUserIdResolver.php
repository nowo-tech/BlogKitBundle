<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Support;

use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\BlogKitBundle\ValueObject\UnreadableBlogUserRef;

use function method_exists;

/**
 * Compares blameable / security users by id, then by identifier.
 *
 * Since 1.9.0 a {@see BlogUserRef} (`doctrine.user_reference: id`) is compared by id only, byte for byte
 * (REQ-SEC-006): its identifier is its id, which must never match another user's login identifier.
 */
final class BlogUserIdResolver
{
    public static function idOf(?object $user): ?string
    {
        if ($user === null || !method_exists($user, 'getId')) {
            return null;
        }

        $id = $user->getId();
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;
    }

    public static function isSame(?object $left, ?object $right): bool
    {
        // Audit ON-M1: an unreadable stored id is the same as nobody.
        if ($left instanceof UnreadableBlogUserRef || $right instanceof UnreadableBlogUserRef) {
            return false;
        }

        $leftId  = self::idOf($left);
        $rightId = self::idOf($right);
        if ($leftId !== null && $rightId !== null && $leftId === $rightId) {
            return true;
        }

        if ($left instanceof BlogUserRef || $right instanceof BlogUserRef) {
            return false;
        }

        if ($left instanceof BlogUserInterface && $right instanceof BlogUserInterface) {
            $leftIdentifier  = $left->getUserIdentifier();
            $rightIdentifier = $right->getUserIdentifier();

            return $leftIdentifier !== '' && $leftIdentifier === $rightIdentifier;
        }

        return false;
    }
}
