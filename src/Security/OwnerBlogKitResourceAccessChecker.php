<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Support\BlogUserIdResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;

/**
 * Editors manage publications they created. Configure roles bypass ownership.
 * Tags and comments stay role-gated unless a host `service` replaces this checker.
 */
final readonly class OwnerBlogKitResourceAccessChecker implements BlogKitResourceAccessCheckerInterface
{
    public function __construct(
        private BlogKitAccessCheckerInterface $accessChecker,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function canManageArticle(BlogArticle $article): bool
    {
        if ($this->accessChecker->canConfigure()) {
            return true;
        }

        $owner = $article->getCreatedBy();
        if ($owner === null) {
            return true;
        }

        return BlogUserIdResolver::isSame($owner, $this->currentUser());
    }

    public function canManageTag(BlogTag $tag): bool
    {
        return true;
    }

    public function canModerateComment(BlogComment $comment): bool
    {
        return true;
    }

    public function articleListingCreatedById(): ?string
    {
        if ($this->accessChecker->canConfigure()) {
            return null;
        }

        return BlogUserIdResolver::idOf($this->currentUser()) ?? '';
    }

    private function currentUser(): ?object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }
}
