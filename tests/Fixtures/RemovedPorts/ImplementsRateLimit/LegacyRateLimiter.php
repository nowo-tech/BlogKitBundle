<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\ImplementsRateLimit;

use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;

/**
 * A 1.x host comment rate limiter still on the port removed in 2.0 (W1-1): it cannot be loaded any more.
 */
final class LegacyRateLimiter implements BlogCommentRateLimiterInterface
{
}
