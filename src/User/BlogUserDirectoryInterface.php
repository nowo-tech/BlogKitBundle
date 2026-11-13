<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\User;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 2, optional with `doctrine.user_reference: id`): what a page shows for the user id a
 * blog row keeps (the "updated by" column of the admin lists).
 *
 * Return the label of exactly that id, or null when the id names nobody; the bundle then shows the id itself. Ids are
 * exact (REQ-SEC-006): never look them up case- or accent-insensitively.
 */
interface BlogUserDirectoryInterface
{
    public function labelOf(string $userId): ?string;
}
