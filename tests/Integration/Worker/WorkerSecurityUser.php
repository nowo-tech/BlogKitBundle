<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The worker tests' security user: plain PHP, implements nothing of the bundle.
 */
final readonly class WorkerSecurityUser implements UserInterface
{
    public function __construct(
        private int|string $id,
        private string $identifier,
    ) {
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_EDITOR'];
    }

    /**
     * Required by Symfony 7.4 {@see UserInterface}; removed from the interface in Symfony 8.
     */
    public function eraseCredentials(): void
    {
    }
}
