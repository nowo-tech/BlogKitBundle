<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security\RateLimit;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Security\RateLimit\CacheBlogCommentRateLimiter;
use Nowo\BlogKitBundle\Security\RateLimit\NullBlogCommentRateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class CacheBlogCommentRateLimiterTest extends TestCase
{
    #[Test]
    public function nullLimiterIsANoOp(): void
    {
        (new NullBlogCommentRateLimiter())->consume(Request::create('/'), new BlogArticle());
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function skipsWhenDisabledOrUnsupported(): void
    {
        $article = (new BlogArticle())->setSlug('post');
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $cache   = new ArrayAdapter();
        $clock   = new MockClock();

        (new CacheBlogCommentRateLimiter($cache, $clock, 0, 60, CommentRateLimitStrategy::FixedWindow))
            ->consume($request, $article);
        (new CacheBlogCommentRateLimiter($cache, $clock, 5, 0, CommentRateLimitStrategy::FixedWindow))
            ->consume($request, $article);
        (new CacheBlogCommentRateLimiter(null, $clock, 5, 60, CommentRateLimitStrategy::FixedWindow))
            ->consume($request, $article);
        (new CacheBlogCommentRateLimiter($cache, $clock, 5, 60, CommentRateLimitStrategy::None))
            ->consume($request, $article);
        (new CacheBlogCommentRateLimiter($cache, $clock, 5, 60, CommentRateLimitStrategy::Service))
            ->consume($request, $article);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function fixedWindowAllowsThenBlocks(): void
    {
        $limiter = $this->limiter(CommentRateLimitStrategy::FixedWindow, 1);
        $article = (new BlogArticle())->setSlug('post');
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $limiter->consume($request, $article);

        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->consume($request, $article);
    }

    #[Test]
    public function fixedWindowIncrementsWithinTheSameWindow(): void
    {
        $limiter = $this->limiter(CommentRateLimitStrategy::FixedWindow, 2);
        $article = (new BlogArticle())->setSlug('post');
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $limiter->consume($request, $article);
        $limiter->consume($request, $article);

        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->consume($request, $article);
    }

    #[Test]
    public function perIpArticleIsolatesSlugs(): void
    {
        $limiter = $this->limiter(CommentRateLimitStrategy::PerIpArticle, 1);
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $limiter->consume($request, (new BlogArticle())->setSlug('one'));
        $limiter->consume($request, (new BlogArticle())->setSlug('two'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function slidingWindowPrunesOldHitsAndUsesUnknownIp(): void
    {
        $cache   = new ArrayAdapter();
        $clock   = new MockClock('2026-08-18 12:00:00');
        $limiter = new CacheBlogCommentRateLimiter($cache, $clock, 2, 10, CommentRateLimitStrategy::SlidingWindow);
        $article = (new BlogArticle())->setSlug('post');
        $request = Request::create('/');

        $limiter->consume($request, $article);
        $clock->modify('+11 seconds');
        $limiter->consume($request, $article);
        $limiter->consume($request, $article);

        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->consume($request, $article);
    }

    #[Test]
    public function slidingWindowIgnoresCorruptCachePayload(): void
    {
        $cache   = new ArrayAdapter();
        $clock   = new MockClock();
        $limiter = new CacheBlogCommentRateLimiter($cache, $clock, 1, 60, CommentRateLimitStrategy::SlidingWindow);
        $article = (new BlogArticle())->setSlug('post');
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);

        $item = $cache->getItem('nowo_blog_kit_comment_sliding_window_' . hash('sha256', '10.0.0.1'));
        $item->set('not-an-array');
        $cache->save($item);

        $limiter->consume($request, $article);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function fixedWindowResetsAfterIntervalAndIgnoresCorruptPayload(): void
    {
        $cache   = new ArrayAdapter();
        $clock   = new MockClock('2026-08-18 12:00:00');
        $limiter = new CacheBlogCommentRateLimiter($cache, $clock, 1, 10, CommentRateLimitStrategy::FixedWindow);
        $article = (new BlogArticle())->setSlug('post');
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '10.0.0.2']);

        $limiter->consume($request, $article);
        $clock->modify('+10 seconds');
        $limiter->consume($request, $article);

        $item = $cache->getItem('nowo_blog_kit_comment_fixed_window_' . hash('sha256', '10.0.0.2'));
        $item->set(['x' => 1]);
        $cache->save($item);
        $limiter->consume($request, $article);

        $this->addToAssertionCount(1);
    }

    private function limiter(CommentRateLimitStrategy $strategy, int $limit): CacheBlogCommentRateLimiter
    {
        return new CacheBlogCommentRateLimiter(new ArrayAdapter(), new MockClock(), $limit, 60, $strategy);
    }
}
