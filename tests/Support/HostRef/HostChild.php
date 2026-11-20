<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support\HostRef;

use Doctrine\ORM\Mapping as ORM;

/**
 * A child of {@see HostParent} (orphan removal).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_ref_child')]
class HostChild
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HostParent::class, inversedBy: 'children')]
    public ?HostParent $parent = null;
}
