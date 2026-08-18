<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\RateLimit;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

use function count;
use function hash;
use function in_array;
use function is_array;
use function sprintf;

/**
 * Cache-backed comment rate limiter (fixed window, per IP+article, or sliding window).
 */
final readonly class CacheBlogCommentRateLimiter implements BlogCommentRateLimiterInterface
{
    public function __construct(
        private ?CacheItemPoolInterface $cachePool,
        private ClockInterface $clock,
        private int $limit,
        private int $intervalSeconds,
        private CommentRateLimitStrategy $strategy,
    ) {
    }

    public function consume(Request $request, BlogArticle $blogArticle): void
    {
        $cachePool = $this->cachePool;
        if (
            $this->limit <= 0
            || $this->intervalSeconds <= 0
            || !$cachePool instanceof CacheItemPoolInterface
            || !in_array($this->strategy, [
                CommentRateLimitStrategy::FixedWindow,
                CommentRateLimitStrategy::PerIpArticle,
                CommentRateLimitStrategy::SlidingWindow,
            ], true)
        ) {
            return;
        }

        $ip  = $request->getClientIp() ?? 'unknown';
        $key = $this->cacheKey($ip, $blogArticle);
        $now = $this->clock->now()->getTimestamp();

        if ($this->strategy === CommentRateLimitStrategy::SlidingWindow) {
            $this->consumeSliding($cachePool, $key, $now);

            return;
        }

        $this->consumeFixed($cachePool, $key, $now);
    }

    private function cacheKey(string $ip, BlogArticle $blogArticle): string
    {
        $material = $this->strategy === CommentRateLimitStrategy::PerIpArticle
            ? $ip . '|' . $blogArticle->getSlug()
            : $ip;

        return 'nowo_blog_kit_comment_' . $this->strategy->value . '_' . hash('sha256', $material);
    }

    private function consumeFixed(CacheItemPoolInterface $cachePool, string $key, int $now): void
    {
        $item = $cachePool->getItem($key);
        $data = $item->isHit() ? $item->get() : null;

        if ($data === null || !isset($data['s'], $data['c']) || ($now - (int) $data['s']) >= $this->intervalSeconds) {
            $data = ['s' => $now, 'c' => 1];
        } else {
            $data['c'] = (int) $data['c'] + 1;
        }

        if ($data['c'] > $this->limit) {
            throw new TooManyRequestsHttpException($this->intervalSeconds, sprintf('Too many comments. Limit is %d per %d seconds.', $this->limit, $this->intervalSeconds));
        }

        $item->set($data);
        $item->expiresAfter($this->intervalSeconds + 10);
        $cachePool->save($item);
    }

    private function consumeSliding(CacheItemPoolInterface $cachePool, string $key, int $now): void
    {
        $item   = $cachePool->getItem($key);
        $stored = $item->isHit() ? $item->get() : [];
        $cutoff = $now - $this->intervalSeconds;
        $stamps = [];

        if (is_array($stored)) {
            foreach ($stored as $stamp) {
                if ((int) $stamp >= $cutoff) {
                    $stamps[] = (int) $stamp;
                }
            }
        }

        if (count($stamps) >= $this->limit) {
            throw new TooManyRequestsHttpException($this->intervalSeconds, sprintf('Too many comments. Limit is %d per %d seconds.', $this->limit, $this->intervalSeconds));
        }

        $stamps[] = $now;
        $item->set($stamps);
        $item->expiresAfter($this->intervalSeconds + 10);
        $cachePool->save($item);
    }
}
