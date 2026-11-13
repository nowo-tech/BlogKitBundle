<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support\Twin;

use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogTagSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The 1.8.3 owner policy written against the twin port (snapshots, ids): a DDD host's policy.
 */
final readonly class OwnerResourcePolicy implements BlogKitResourcePolicyInterface
{
    public function __construct(
        private BlogKitAccessCheckerInterface $accessChecker,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function canManageArticle(BlogArticleSnapshot $article): bool
    {
        return $this->accessChecker->canConfigure() || $article->createdById === null || $article->createdById === $this->currentId();
    }

    public function canManageTag(BlogTagSnapshot $tag): bool
    {
        return true;
    }

    public function canModerateComment(BlogCommentSnapshot $comment): bool
    {
        return true;
    }

    public function articleListingCreatedById(): ?string
    {
        return $this->accessChecker->canConfigure() ? null : ($this->currentId() ?? '');
    }

    private function currentId(): ?string
    {
        return BlogUserRef::idOf($this->tokenStorage->getToken()?->getUser());
    }
}
