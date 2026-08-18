<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Model\BlogUserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'blog_kit_test_user')]
final class MappedTestUser implements BlogUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = trim($email);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}
