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
     * @param list<string> $accessRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $manageRoles,
        private array $moderateRoles,
        private array $configureRoles,
        private array $accessRoles = [],
    ) {
    }

    public function canManage(): bool
    {
        return $this->grantedAny($this->accessRoles, false)
            || $this->grantedAny($this->manageRoles, true);
    }

    public function canModerate(): bool
    {
        return $this->grantedAny($this->accessRoles, false)
            || $this->grantedAny($this->moderateRoles, true)
            || $this->canManage();
    }

    public function canConfigure(): bool
    {
        return $this->grantedAny($this->accessRoles, false)
            || $this->grantedAny($this->configureRoles, true);
    }

    /**
     * @param list<string> $roles
     */
    private function grantedAny(array $roles, bool $emptyAllows): bool
    {
        if ($roles === []) {
            return $emptyAllows;
        }

        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
