<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;

/**
 * Object access is a no-op: anyone who passed the role checker may act on every row.
 */
final class AllowAllBlogKitResourceAccessChecker implements BlogKitResourceAccessCheckerInterface
{
    public function canManageArticle(BlogArticle $article): bool
    {
        return true;
    }

    public function canManageTag(BlogTag $tag): bool
    {
        return true;
    }

    public function canModerateComment(BlogComment $comment): bool
    {
        return true;
    }

    public function articleListingCreatedById(): int|string|null
    {
        return null;
    }
}
