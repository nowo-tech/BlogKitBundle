<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller\Admin;

use Nowo\BlogKitBundle\User\BlogUsers;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Since 1.9.0 (audit ON-M1 / ON-R1): with `doctrine.user_reference: id`, a signed-in user whose id the bundle cannot
 * keep exactly may not write articles, tags or settings. Checked at the top of every write route, before the form
 * touches any entity, so a refused request leaves nothing pending on the entity manager.
 */
trait DeniesUnrecordableUserTrait
{
    /**
     * @throws AccessDeniedException
     */
    private function denyUnrecordableUser(?BlogUsers $users): void
    {
        if ($users?->isUnrecordable() === true) {
            throw new AccessDeniedException('Your user id cannot be recorded by the blog (doctrine.user_reference: id).');
        }
    }
}
