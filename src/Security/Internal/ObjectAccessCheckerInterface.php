<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Internal;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;

/**
 * Since 2.0: how the bundle's controllers and Twig functions ask the object-level admin access (after the role checker
 * granted the route). Implemented by the built-in `none` / `owner` checkers and by the gate that asks your
 * {@see BlogKitResourcePolicyInterface} (`security.object_access.strategy: service`) with snapshots.
 *
 * @internal not an extension point: implement {@see BlogKitResourcePolicyInterface}. It replaces, inside the bundle,
 *           the 1.x `Security\BlogKitResourceAccessCheckerInterface` removed in 2.0
 */
interface ObjectAccessCheckerInterface
{
    public function canManageArticle(BlogArticle $article): bool;

    public function canManageTag(BlogTag $tag): bool;

    public function canModerateComment(BlogComment $comment): bool;

    /**
     * Restricts admin article listings to this `createdBy` id. Null = no extra filter. Empty string = empty listing
     * (no current user).
     */
    public function articleListingCreatedById(): int|string|null;
}
