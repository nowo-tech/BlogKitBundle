<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\RateLimit;

use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRateLimitDecision;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 3): the public comment rate limit without the HTTP `Request` nor the article entity.
 * Configure it with `comments.rate_limit: { strategy: service, service: <id> }`. Since 2.0 it is the only interface that
 * key takes (the 1.x twin `BlogCommentRateLimiterInterface` was removed).
 * A denied decision answers HTTP 429 with its `Retry-After`.
 */
interface BlogCommentRateLimitPolicyInterface
{
    public function decide(BlogCommentRequestContext $request, BlogArticleSnapshot $article): BlogCommentRateLimitDecision;
}
