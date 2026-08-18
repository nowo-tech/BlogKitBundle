<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

final class AllowAllBlogKitAccessChecker implements BlogKitAccessCheckerInterface
{
    public function canManage(): bool
    {
        return true;
    }

    public function canModerate(): bool
    {
        return true;
    }

    public function canConfigure(): bool
    {
        return true;
    }
}
