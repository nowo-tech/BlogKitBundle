<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Internal;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 2.0: how the public comment controller consumes one submission against the active rate-limit strategy.
 * Implemented by the built-in limiters and by the gate that asks your {@see BlogCommentRateLimitPolicyInterface}
 * (`comments.rate_limit.strategy: service`).
 *
 * @internal not an extension point: implement {@see BlogCommentRateLimitPolicyInterface}. It replaces, inside the
 *           bundle, the 1.x `Security\RateLimit\BlogCommentRateLimiterInterface` removed in 2.0
 */
interface CommentRateLimiterInterface
{
    public function consume(Request $request, BlogArticle $blogArticle): void;
}
