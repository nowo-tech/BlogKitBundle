<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\RateLimit;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Symfony\Component\HttpFoundation\Request;

/**
 * Consumes one public comment submission against the active rate-limit strategy.
 */
interface BlogCommentRateLimiterInterface
{
    public function consume(Request $request, BlogArticle $blogArticle): void;
}
