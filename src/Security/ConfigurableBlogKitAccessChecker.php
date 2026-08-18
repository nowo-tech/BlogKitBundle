<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Role-based access driven by nowo_blog_kit.security.*_roles.
 */
final readonly class ConfigurableBlogKitAccessChecker implements BlogKitAccessCheckerInterface
{
    /**
     * @param list<string> $manageRoles
     * @param list<string> $moderateRoles
     * @param list<string> $configureRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $manageRoles,
        private array $moderateRoles,
        private array $configureRoles,
    ) {
    }

    public function canManage(): bool
    {
        return $this->grantedAny($this->manageRoles);
    }

    public function canModerate(): bool
    {
        return $this->grantedAny($this->moderateRoles) || $this->canManage();
    }

    public function canConfigure(): bool
    {
        return $this->grantedAny($this->configureRoles);
    }

    /** @param list<string> $roles */
    private function grantedAny(array $roles): bool
    {
        if ($roles === []) {
            return true;
        }

        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
