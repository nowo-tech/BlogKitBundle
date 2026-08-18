<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Support;

use Nowo\BlogKitBundle\Model\BlogUserInterface;

use function method_exists;

/**
 * Compares blameable / security users by id, then by identifier.
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
        $leftId  = self::idOf($left);
        $rightId = self::idOf($right);
        if ($leftId !== null && $rightId !== null && $leftId === $rightId) {
            return true;
        }

        if ($left instanceof BlogUserInterface && $right instanceof BlogUserInterface) {
            $leftIdentifier  = $left->getUserIdentifier();
            $rightIdentifier = $right->getUserIdentifier();

            return $leftIdentifier !== '' && $leftIdentifier === $rightIdentifier;
        }

        return false;
    }
}
