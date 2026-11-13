<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Support\BlogUserIdResolver;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\BlogKitBundle\ValueObject\UnreadableBlogUserRef;
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
        private ?BlogUsers $users = null,
    ) {
    }

    public function canManageArticle(BlogArticle $article): bool
    {
        if ($this->accessChecker->canConfigure()) {
            return true;
        }

        $owner = $article->getCreatedBy();
        // A really NULL column (legacy rows, no signed-in creator): any manager, as in 1.8.
        if ($owner === null) {
            return true;
        }

        // Audit ON-M1: a stored id the bundle cannot read is an owner nobody matches (fail closed).
        if ($owner instanceof UnreadableBlogUserRef) {
            return false;
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

        $id = BlogUserIdResolver::idOf($this->currentUser());
        if ($id === null) {
            return '';
        }

        // Since 1.9.0 (REQ-SEC-006, `user_reference: id` only, audit ON-I1): an id that cannot be matched byte for
        // byte lists nothing (fail closed). The entity mode compares the host's primary key as in 1.8.
        return $this->users?->idMode() === true && !BlogUserRef::isValid($id) ? '' : $id;
    }

    private function currentUser(): ?object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }
}
