<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Port;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Since 1.9.0: asks a {@see BlogCommentRateLimitPolicyInterface} (the twin) where the bundle asks the 1.x port; a denied
 * decision throws the same HTTP 429 the 1.x limiters throw.
 *
 * @internal built by {@see Ports}
 */
final readonly class BlogCommentRateLimitPolicyGate implements BlogCommentRateLimiterInterface
{
    public function __construct(
        private BlogCommentRateLimitPolicyInterface $policy,
    ) {
    }

    public function consume(Request $request, BlogArticle $blogArticle): void
    {
        $decision = $this->policy->decide(BlogCommentRequestContexts::fromRequest($request), BlogArticleSnapshot::of($blogArticle));
        if (!$decision->allowed) {
            throw new TooManyRequestsHttpException($decision->retryAfterSeconds, $decision->reason);
        }
    }
}
