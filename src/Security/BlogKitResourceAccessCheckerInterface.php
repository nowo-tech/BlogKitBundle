<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;

/**
 * Object-level admin access after the role checker has already granted the route.
 *
 * Replace via `security.object_access.strategy: service` (or use built-in `owner`).
 */
interface BlogKitResourceAccessCheckerInterface
{
    public function canManageArticle(BlogArticle $article): bool;

    public function canManageTag(BlogTag $tag): bool;

    public function canModerateComment(BlogComment $comment): bool;

    /**
     * Restricts admin article listings to this `createdBy` id.
     * Null = no extra filter. Empty string = empty listing (no current user).
     */
    public function articleListingCreatedById(): int|string|null;
}
