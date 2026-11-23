<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A host user that is both the Doctrine entity of `user_class` and the security user, as AuditKit's blame needs it
 * (`WorkerTestKernel` with NowoAuditKitBundle). Not final: your manager hands out proxies of it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_kit_audited_user')]
class AuditedTestUser implements BlogUserInterface, UserInterface
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
        return ['ROLE_EDITOR'];
    }

    public function eraseCredentials(): void
    {
    }
}
