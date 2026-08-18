<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

/**
 * Moderation state for blog comments.
 */
enum BlogCommentStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'pending',
            self::Approved => 'approved',
            self::Rejected => 'rejected',
        };
    }
}
