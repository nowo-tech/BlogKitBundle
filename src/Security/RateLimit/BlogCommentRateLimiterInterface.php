<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\RateLimit;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Symfony\Component\HttpFoundation\Request;

/**
 * Consumes one public comment submission against the active rate-limit strategy.
 *
 * @deprecated since 1.9.0, removed in 2.0: implement {@see BlogCommentRateLimitPolicyInterface} (no HTTP `Request`, no
 *             entity); the same configuration key takes it
 */
interface BlogCommentRateLimiterInterface
{
    public function consume(Request $request, BlogArticle $blogArticle): void;
}
