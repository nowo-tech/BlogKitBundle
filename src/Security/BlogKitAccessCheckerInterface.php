<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

/**
 * Host-provided (or role-based) access for blog admin surfaces.
 */
interface BlogKitAccessCheckerInterface
{
    public function canManage(): bool;

    public function canModerate(): bool;

    public function canConfigure(): bool;
}
