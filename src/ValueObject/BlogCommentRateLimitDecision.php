<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;

/**
 * Since 1.9.0: answer of a {@see BlogCommentRateLimitPolicyInterface}. A denied
 * comment gets HTTP 429 with `Retry-After: $retryAfterSeconds`.
 */
final readonly class BlogCommentRateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public int $retryAfterSeconds = 0,
        public string $reason = '',
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(int $retryAfterSeconds, string $reason = 'Too many comments.'): self
    {
        return new self(false, max(0, $retryAfterSeconds), $reason);
    }
}
