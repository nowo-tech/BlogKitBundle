<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\RateLimit;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Symfony\Component\HttpFoundation\Request;

/**
 * No-op rate limiter (`none` strategy, missing cache, or limit 0).
 */
final class NullBlogCommentRateLimiter implements BlogCommentRateLimiterInterface
{
    public function consume(Request $request, BlogArticle $blogArticle): void
    {
    }
}
