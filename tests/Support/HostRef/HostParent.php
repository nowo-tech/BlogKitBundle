<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support\HostRef;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entity of the host application with its own pending work of every kind (audit XD2-1): a field, an orphan-removal
 * collection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_ref_parent')]
class HostParent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 50)]
    public string $name = 'parent';

    /** @var Collection<int, HostChild> */
    #[ORM\OneToMany(targetEntity: HostChild::class, mappedBy: 'parent', cascade: ['persist'], orphanRemoval: true)]
    public Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function addChild(): self
    {
        $child         = new HostChild();
        $child->parent = $this;
        $this->children->add($child);

        return $this;
    }
}
