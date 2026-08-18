<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\TimestampableTrait;

/**
 * Audit timestamps and blame fields mapped to {@see BlogUserInterface}.
 */
trait BlameableUserTrait
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: BlogUserInterface::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?object $createdBy = null;

    #[ORM\ManyToOne(targetEntity: BlogUserInterface::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?object $updatedBy = null;

    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?object $createdBy): void
    {
        $this->createdBy = $createdBy instanceof BlogUserInterface ? $createdBy : null;
    }

    public function getUpdatedBy(): ?object
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?object $updatedBy): void
    {
        $this->updatedBy = $updatedBy instanceof BlogUserInterface ? $updatedBy : null;
    }
}
