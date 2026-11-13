<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Port;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogTagSnapshot;

/**
 * Since 1.9.0: asks a {@see BlogKitResourcePolicyInterface} (the twin) where the bundle asks the 1.x port. Snapshots are
 * copied per call.
 *
 * @internal built by {@see Ports}
 */
final readonly class BlogKitResourcePolicyGate implements BlogKitResourceAccessCheckerInterface
{
    public function __construct(
        private BlogKitResourcePolicyInterface $policy,
    ) {
    }

    public function canManageArticle(BlogArticle $article): bool
    {
        return $this->policy->canManageArticle(BlogArticleSnapshot::of($article));
    }

    public function canManageTag(BlogTag $tag): bool
    {
        return $this->policy->canManageTag(BlogTagSnapshot::of($tag));
    }

    public function canModerateComment(BlogComment $comment): bool
    {
        return $this->policy->canModerateComment(BlogCommentSnapshot::of($comment));
    }

    public function articleListingCreatedById(): ?string
    {
        return $this->policy->articleListingCreatedById();
    }
}
