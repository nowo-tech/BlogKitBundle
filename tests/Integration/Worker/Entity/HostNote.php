<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An entity of the host application, on the host's default manager (the bundle must never flush or clear it).
 */
#[ORM\Entity]
#[ORM\Table(name: 'worker_host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 120)]
        private string $text,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
