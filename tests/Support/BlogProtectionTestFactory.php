<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Nowo\BlogKitBundle\Security\BlogProtectionConfig;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Security\Captcha\StreamCaptchaHttpClient;
use Nowo\BlogKitBundle\Security\Html\BlogHtmlSanitizerInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

final class BlogProtectionTestFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function config(array $config = []): BlogProtectionConfig
    {
        return new BlogProtectionConfig(
            $config['rateLimitStrategy'] ?? CommentRateLimitStrategy::FixedWindow,
            $config['rateLimit'] ?? 5,
            $config['interval'] ?? 60,
            $config['rateLimitService'] ?? null,
            $config['captchaStrategy'] ?? CommentCaptchaStrategy::Honeypot,
            $config['siteKey'] ?? '',
            $config['secretKey'] ?? '',
            $config['minScore'] ?? 0.5,
            $config['honeypotField'] ?? 'website',
            $config['captchaService'] ?? null,
            $config['htmlStrategy'] ?? HtmlSanitizeStrategy::None,
            $config['htmlService'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(
        array $config = [],
        ?BlogSettings $settings = null,
        ?CacheItemPoolInterface $cache = null,
        ?ClockInterface $clock = null,
        ?CaptchaHttpClientInterface $http = null,
        ?RequestStack $requestStack = null,
        ?BlogCommentRateLimiterInterface $customRateLimiter = null,
        ?BlogCommentCaptchaStrategyInterface $customCaptcha = null,
        ?BlogHtmlSanitizerInterface $customSanitizer = null,
    ): BlogProtection {
        $settings ??= new BlogSettings();
        $provider = new BlogSettingsProvider(RepositoryTestSupport::blogSettingsRepository($settings));

        return new BlogProtection(
            self::config($config),
            $provider,
            $http ?? new StreamCaptchaHttpClient(static fn (): string => '{"success":true}'),
            $requestStack ?? new RequestStack(),
            $cache ?? new ArrayAdapter(),
            $clock ?? new MockClock(),
            $customRateLimiter,
            $customCaptcha,
            $customSanitizer,
        );
    }
}
