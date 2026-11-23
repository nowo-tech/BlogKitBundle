<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogTagSnapshot;

/**
 * Since 1.9.0 (REQ-ARCH-001 rules 1 and 3): object-level admin access with snapshots instead of the bundle entities.
 * Configure it with `security.object_access: { strategy: service, service: <id> }`. Since 2.0 it is the only interface
 * that key takes (the 1.x twin `BlogKitResourceAccessCheckerInterface` was removed).
 */
interface BlogKitResourcePolicyInterface
{
    public function canManageArticle(BlogArticleSnapshot $article): bool;

    public function canManageTag(BlogTagSnapshot $tag): bool;

    public function canModerateComment(BlogCommentSnapshot $comment): bool;

    /**
     * Restricts admin article listings to this `createdBy` id (compared byte for byte). Null = no extra filter. Empty
     * string = empty listing (no current user).
     */
    public function articleListingCreatedById(): ?string;
}
