<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Model;

/**
 * Optional: a host user that implements it gives the public name of its staff replies. Without it the replies are
 * signed with `nowo_blog_kit.comments.staff_name` — the login identifier is never published (SEC-004 I5).
 */
interface BlogStaffDisplayNameInterface
{
    public function getBlogDisplayName(): string;
}
