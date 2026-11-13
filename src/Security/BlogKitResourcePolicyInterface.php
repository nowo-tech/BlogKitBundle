<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogTagSnapshot;

/**
 * Since 1.9.0 (REQ-ARCH-001 rules 1 and 3): twin of {@see BlogKitResourceAccessCheckerInterface} that takes snapshots
 * instead of the bundle entities. Configure it exactly like the 1.x interface
 * (`security.object_access: { strategy: service, service: <id> }`).
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
