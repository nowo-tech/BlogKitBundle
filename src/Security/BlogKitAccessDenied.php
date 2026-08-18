<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Throws AccessDeniedException when object-level checks fail.
 */
final readonly class BlogKitAccessDenied
{
    public function __construct(
        private BlogKitResourceAccessCheckerInterface $resourceAccess,
    ) {
    }

    public function resourceAccess(): BlogKitResourceAccessCheckerInterface
    {
        return $this->resourceAccess;
    }

    public function denyUnlessCanManageArticle(BlogArticle $article): void
    {
        if (!$this->resourceAccess->canManageArticle($article)) {
            throw new AccessDeniedException('You cannot manage this publication.');
        }
    }

    public function denyUnlessCanManageTag(BlogTag $tag): void
    {
        if (!$this->resourceAccess->canManageTag($tag)) {
            throw new AccessDeniedException('You cannot manage this tag.');
        }
    }

    public function denyUnlessCanModerateComment(BlogComment $comment): void
    {
        if (!$this->resourceAccess->canModerateComment($comment)) {
            throw new AccessDeniedException('You cannot moderate this comment.');
        }
    }
}
