<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class TestUser implements BlogUserInterface, UserInterface
{
    private ?int $id = 1;

    private string $email = 'user@example.test';

    /** @var list<string> */
    private array $roles = ['ROLE_ADMIN'];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }
}
