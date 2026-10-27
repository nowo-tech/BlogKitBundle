<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Role-based access driven by nowo_blog_kit.security.access_roles and security.roles.{manage,moderate,configure}.
 * An empty list grants nothing (fail closed, SEC-004 M3): a capability needs one of its roles or one access role.
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
        return $this->grantedAny($this->accessRoles)
            || $this->grantedAny($this->manageRoles);
    }

    public function canModerate(): bool
    {
        return $this->grantedAny($this->accessRoles)
            || $this->grantedAny($this->moderateRoles)
            || $this->canManage();
    }

    public function canConfigure(): bool
    {
        return $this->grantedAny($this->accessRoles)
            || $this->grantedAny($this->configureRoles);
    }

    /**
     * @param list<string> $roles
     */
    private function grantedAny(array $roles): bool
    {
        return array_any($roles, fn (string $role): bool => $this->authorizationChecker->isGranted($role));
    }
}
