<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Nowo\BlogKitBundle\Security\Captcha\NoneCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Captcha\StreamCaptchaHttpClient;
use Nowo\BlogKitBundle\Security\Html\NullBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\RateLimit\CacheBlogCommentRateLimiter;
use Nowo\BlogKitBundle\Security\RateLimit\NullBlogCommentRateLimiter;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Tests\Support\BlogProtectionTestFactory;
use Nowo\BlogKitBundle\Tests\Support\RepositoryTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class BlogProtectionTest extends TestCase
{
    #[Test]
    public function missingSettingsRowUsesYamlDefaults(): void
    {
        $provider   = new BlogSettingsProvider(RepositoryTestSupport::missingBlogSettingsRepository());
        $protection = new BlogProtection(
            BlogProtectionTestFactory::config(),
            $provider,
            new StreamCaptchaHttpClient(static fn (): string => '{"success":true}'),
            new RequestStack(),
            new ArrayAdapter(),
            new MockClock(),
        );

        self::assertSame(CommentRateLimitStrategy::FixedWindow, $protection->resolveRateLimitStrategy());
        self::assertSame(CommentCaptchaStrategy::Honeypot, $protection->resolveCaptchaStrategy());
        self::assertSame(HtmlSanitizeStrategy::None, $protection->resolveHtmlSanitizeStrategy());
    }

    #[Test]
    public function inheritUsesYamlDefaults(): void
    {
        $protection = BlogProtectionTestFactory::create();

        self::assertSame(CommentRateLimitStrategy::FixedWindow, $protection->resolveRateLimitStrategy());
        self::assertSame(CommentCaptchaStrategy::Honeypot, $protection->resolveCaptchaStrategy());
        self::assertSame(HtmlSanitizeStrategy::None, $protection->resolveHtmlSanitizeStrategy());
        self::assertInstanceOf(CacheBlogCommentRateLimiter::class, $protection->rateLimiter());
        self::assertSame('honeypot', $protection->captcha()->twigContext()['strategy']);
        self::assertSame('<b>x</b>', $protection->htmlSanitizer()->sanitize('<b>x</b>'));
    }

    #[Test]
    public function settingsOverrideYamlAndInvalidFallsBack(): void
    {
        $settings = (new BlogSettings())
            ->setCommentRateLimitStrategy('sliding_window')
            ->setCommentCaptchaStrategy('none')
            ->setHtmlSanitizeStrategy('strip')
            ->setCommentRateLimitLimit(2)
            ->setCommentRateLimitIntervalSeconds(30);

        $protection = BlogProtectionTestFactory::create(settings: $settings);

        self::assertSame(CommentRateLimitStrategy::SlidingWindow, $protection->resolveRateLimitStrategy());
        self::assertSame(CommentCaptchaStrategy::None, $protection->resolveCaptchaStrategy());
        self::assertSame(HtmlSanitizeStrategy::Strip, $protection->resolveHtmlSanitizeStrategy());
        self::assertSame('Hello', $protection->htmlSanitizer()->sanitize('<p>Hello</p>'));

        $invalid = (new BlogSettings())
            ->setCommentRateLimitStrategy('nope')
            ->setCommentCaptchaStrategy('nope')
            ->setHtmlSanitizeStrategy('nope');
        $fallback = BlogProtectionTestFactory::create(settings: $invalid);
        self::assertSame(CommentRateLimitStrategy::FixedWindow, $fallback->resolveRateLimitStrategy());
        self::assertSame(CommentCaptchaStrategy::Honeypot, $fallback->resolveCaptchaStrategy());
        self::assertSame(HtmlSanitizeStrategy::None, $fallback->resolveHtmlSanitizeStrategy());

        $empty = new BlogSettings();
        foreach (['commentRateLimitStrategy', 'commentCaptchaStrategy', 'htmlSanitizeStrategy'] as $property) {
            (new ReflectionProperty(BlogSettings::class, $property))->setValue($empty, '');
        }
        $fromEmpty = BlogProtectionTestFactory::create(settings: $empty);
        self::assertSame(CommentRateLimitStrategy::FixedWindow, $fromEmpty->resolveRateLimitStrategy());
        self::assertSame(CommentCaptchaStrategy::Honeypot, $fromEmpty->resolveCaptchaStrategy());
        self::assertSame(HtmlSanitizeStrategy::None, $fromEmpty->resolveHtmlSanitizeStrategy());
    }

    #[Test]
    public function noneAndZeroLimitUseNullRateLimiter(): void
    {
        $none = BlogProtectionTestFactory::create(['rateLimitStrategy' => CommentRateLimitStrategy::None]);
        self::assertInstanceOf(NullBlogCommentRateLimiter::class, $none->rateLimiter());

        $zero = BlogProtectionTestFactory::create(['rateLimit' => 0]);
        self::assertInstanceOf(NullBlogCommentRateLimiter::class, $zero->rateLimiter());

        $interval = BlogProtectionTestFactory::create(['interval' => 0]);
        self::assertInstanceOf(NullBlogCommentRateLimiter::class, $interval->rateLimiter());
    }

    #[Test]
    public function serviceStrategyUsesCustomImplementationsOrNullFallback(): void
    {
        $customLimiter = $this->createMock(BlogCommentRateLimiterInterface::class);
        $withCustom    = BlogProtectionTestFactory::create(
            [
                'rateLimitStrategy' => CommentRateLimitStrategy::Service,
                'captchaStrategy'   => CommentCaptchaStrategy::Service,
                'htmlStrategy'      => HtmlSanitizeStrategy::Service,
            ],
            customRateLimiter: $customLimiter,
            customCaptcha: new NoneCommentCaptchaStrategy(),
            customSanitizer: new NullBlogHtmlSanitizer(),
        );
        self::assertSame($customLimiter, $withCustom->rateLimiter());
        self::assertInstanceOf(NoneCommentCaptchaStrategy::class, $withCustom->captcha());
        self::assertInstanceOf(NullBlogHtmlSanitizer::class, $withCustom->htmlSanitizer());

        $without = BlogProtectionTestFactory::create([
            'rateLimitStrategy' => CommentRateLimitStrategy::Service,
            'captchaStrategy'   => CommentCaptchaStrategy::Service,
            'htmlStrategy'      => HtmlSanitizeStrategy::Service,
        ]);
        self::assertInstanceOf(NullBlogCommentRateLimiter::class, $without->rateLimiter());
        self::assertSame('none', $without->captcha()->twigContext()['strategy']);
        self::assertSame('<i>x</i>', $without->htmlSanitizer()->sanitize('<i>x</i>'));
    }

    #[Test]
    public function allowlistAndRemoteCaptchaStrategiesAreResolved(): void
    {
        $protection = BlogProtectionTestFactory::create([
            'captchaStrategy' => CommentCaptchaStrategy::Hcaptcha,
            'htmlStrategy'    => HtmlSanitizeStrategy::Allowlist,
            'siteKey'         => 'site',
            'secretKey'       => 'secret',
        ]);

        self::assertSame('hcaptcha', $protection->captcha()->twigContext()['strategy']);
        self::assertStringNotContainsString('script', $protection->htmlSanitizer()->sanitize('<p>a</p><script>x</script>'));
        $protection->rateLimiter()->consume(Request::create('/'), (new BlogArticle())->setSlug('post'));

        foreach ([CommentCaptchaStrategy::RecaptchaV2, CommentCaptchaStrategy::RecaptchaV3, CommentCaptchaStrategy::Turnstile] as $strategy) {
            $remote = BlogProtectionTestFactory::create([
                'captchaStrategy' => $strategy,
                'siteKey'         => 'site',
                'secretKey'       => 'secret',
            ]);
            self::assertSame($strategy->value, $remote->captcha()->twigContext()['strategy']);
        }
    }

    #[Test]
    public function nativeClockIsUsedWhenClockIsOmitted(): void
    {
        $protection = new BlogProtection(
            BlogProtectionTestFactory::config(['rateLimitStrategy' => CommentRateLimitStrategy::None]),
            new BlogSettingsProvider(
                RepositoryTestSupport::blogSettingsRepository(new BlogSettings()),
            ),
            new StreamCaptchaHttpClient(static fn (): string => '{}'),
            new RequestStack(),
        );

        self::assertInstanceOf(NullBlogCommentRateLimiter::class, $protection->rateLimiter());

        $noCache = new BlogProtection(
            BlogProtectionTestFactory::config(),
            new BlogSettingsProvider(
                RepositoryTestSupport::blogSettingsRepository(new BlogSettings()),
            ),
            new StreamCaptchaHttpClient(static fn (): string => '{}'),
            new RequestStack(),
        );
        $noCache->rateLimiter()->consume(Request::create('/'), (new BlogArticle())->setSlug('post'));
        $this->addToAssertionCount(1);
    }
}
