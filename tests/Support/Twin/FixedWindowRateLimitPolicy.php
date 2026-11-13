<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support\Twin;

use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Nowo\BlogKitBundle\Security\RateLimit\CacheBlogCommentRateLimiter;
use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRateLimitDecision;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

use function hash;
use function is_array;
use function sprintf;

/**
 * The 1.8.3 per-IP-and-article fixed window written against the twin port.
 */
final readonly class FixedWindowRateLimitPolicy implements BlogCommentRateLimitPolicyInterface
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private int $limit,
        private int $intervalSeconds,
    ) {
    }

    public function decide(BlogCommentRequestContext $request, BlogArticleSnapshot $article): BlogCommentRateLimitDecision
    {
        $key  = 'twin_' . hash('sha256', CacheBlogCommentRateLimiter::clientKey($request->clientIp) . '|' . $article->slug);
        $now  = $this->clock->now()->getTimestamp();
        $item = $this->cache->getItem($key);
        $data = $item->isHit() ? $item->get() : null;
        if (!is_array($data) || ($now - (int) $data['s']) >= $this->intervalSeconds) {
            $data = ['s' => $now, 'c' => 1];
        } else {
            $data['c'] = (int) $data['c'] + 1;
        }

        if ($data['c'] > $this->limit) {
            return BlogCommentRateLimitDecision::deny($this->intervalSeconds, sprintf('Too many comments. Limit is %d per %d seconds.', $this->limit, $this->intervalSeconds));
        }

        $item->set($data);
        $item->expiresAfter($this->intervalSeconds + 10);
        $this->cache->save($item);

        return BlogCommentRateLimitDecision::allow();
    }
}
