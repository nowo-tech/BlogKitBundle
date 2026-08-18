<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Model;

/**
 * Host user entity mapped via nowo_blog_kit.user_class + ResolveTargetEntity.
 */
interface BlogUserInterface
{
    public function getId(): mixed;

    public function getUserIdentifier(): string;
}
