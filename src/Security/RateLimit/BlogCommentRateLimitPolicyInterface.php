<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\RateLimit;

use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRateLimitDecision;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 3): twin of {@see BlogCommentRateLimiterInterface} without the HTTP `Request` nor the
 * article entity. Configure it exactly like the 1.x interface (`comments.rate_limit: { strategy: service, service: <id> }`).
 * A denied decision answers HTTP 429 with its `Retry-After`.
 */
interface BlogCommentRateLimitPolicyInterface
{
    public function decide(BlogCommentRequestContext $request, BlogArticleSnapshot $article): BlogCommentRateLimitDecision;
}
