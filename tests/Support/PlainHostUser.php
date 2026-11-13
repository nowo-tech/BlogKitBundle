<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A DDD host's security user: plain PHP, implements nothing of the bundle (REQ-ARCH-001 rule 2).
 */
final readonly class PlainHostUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private mixed $id,
        private string $identifier = 'user',
        private array $roles = ['ROLE_ADMIN'],
    ) {
    }

    public function getId(): mixed
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier !== '' ? $this->identifier : 'anonymous';
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * Required by Symfony 7.4 {@see UserInterface}; removed from the interface in Symfony 8.
     */
    public function eraseCredentials(): void
    {
    }
}
