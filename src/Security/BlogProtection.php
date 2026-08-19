<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Security\Captcha\HoneypotCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Captcha\NoneCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Captcha\RemoteCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Html\AllowlistBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\Html\BlogHtmlSanitizerInterface;
use Nowo\BlogKitBundle\Security\Html\NullBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\Html\StripBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\RateLimit\CacheBlogCommentRateLimiter;
use Nowo\BlogKitBundle\Security\RateLimit\NullBlogCommentRateLimiter;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves YAML defaults plus admin settings overrides into live strategies.
 */
final readonly class BlogProtection
{
    private ClockInterface $clock;

    public function __construct(
        private BlogProtectionConfig $config,
        private BlogSettingsProvider $settingsProvider,
        private CaptchaHttpClientInterface $captchaHttpClient,
        private RequestStack $requestStack,
        private ?CacheItemPoolInterface $cachePool = null,
        ?ClockInterface $clock = null,
        private ?BlogCommentRateLimiterInterface $customRateLimiter = null,
        private ?BlogCommentCaptchaStrategyInterface $customCaptcha = null,
        private ?BlogHtmlSanitizerInterface $customSanitizer = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    public function rateLimiter(): BlogCommentRateLimiterInterface
    {
        $strategy = $this->resolveRateLimitStrategy();
        $limit    = $this->resolveRateLimit();
        $interval = $this->resolveRateLimitInterval();

        if ($strategy === CommentRateLimitStrategy::None || $limit <= 0 || $interval <= 0) {
            return new NullBlogCommentRateLimiter();
        }

        if ($strategy === CommentRateLimitStrategy::Service) {
            return $this->customRateLimiter ?? new NullBlogCommentRateLimiter();
        }

        return new CacheBlogCommentRateLimiter(
            $this->cachePool,
            $this->clock,
            $limit,
            $interval,
            $strategy,
        );
    }

    public function captcha(): BlogCommentCaptchaStrategyInterface
    {
        $strategy = $this->resolveCaptchaStrategy();

        return match ($strategy) {
            CommentCaptchaStrategy::None     => new NoneCommentCaptchaStrategy(),
            CommentCaptchaStrategy::Honeypot => new HoneypotCommentCaptchaStrategy($this->config->honeypotField),
            CommentCaptchaStrategy::RecaptchaV2,
            CommentCaptchaStrategy::RecaptchaV3,
            CommentCaptchaStrategy::Hcaptcha,
            CommentCaptchaStrategy::Turnstile => new RemoteCommentCaptchaStrategy(
                $strategy,
                $this->captchaHttpClient,
                $this->requestStack,
                $this->config->captchaSiteKey,
                $this->config->captchaSecretKey,
                $this->config->captchaMinScore,
            ),
            CommentCaptchaStrategy::Service => $this->customCaptcha ?? new NoneCommentCaptchaStrategy(),
        };
    }

    public function htmlSanitizer(): BlogHtmlSanitizerInterface
    {
        $strategy = $this->resolveHtmlSanitizeStrategy();

        return match ($strategy) {
            HtmlSanitizeStrategy::None      => new NullBlogHtmlSanitizer(),
            HtmlSanitizeStrategy::Strip     => new StripBlogHtmlSanitizer(),
            HtmlSanitizeStrategy::Allowlist => new AllowlistBlogHtmlSanitizer(),
            HtmlSanitizeStrategy::Service   => $this->customSanitizer ?? new NullBlogHtmlSanitizer(),
        };
    }

    public function resolveRateLimitStrategy(): CommentRateLimitStrategy
    {
        $settings = $this->settingsProvider->findSettings();
        if (!$settings instanceof BlogSettings) {
            return $this->config->rateLimitStrategy;
        }

        $override = $settings->getCommentRateLimitStrategy();
        if ($override === CommentRateLimitStrategy::Inherit || $override === '') {
            return $this->config->rateLimitStrategy;
        }

        return CommentRateLimitStrategy::tryFrom($override) ?? $this->config->rateLimitStrategy;
    }

    public function resolveCaptchaStrategy(): CommentCaptchaStrategy
    {
        $settings = $this->settingsProvider->findSettings();
        if (!$settings instanceof BlogSettings) {
            return $this->config->captchaStrategy;
        }

        $override = $settings->getCommentCaptchaStrategy();
        if ($override === CommentCaptchaStrategy::Inherit || $override === '') {
            return $this->config->captchaStrategy;
        }

        return CommentCaptchaStrategy::tryFrom($override) ?? $this->config->captchaStrategy;
    }

    public function resolveHtmlSanitizeStrategy(): HtmlSanitizeStrategy
    {
        $settings = $this->settingsProvider->findSettings();
        if (!$settings instanceof BlogSettings) {
            return $this->config->htmlSanitizeStrategy;
        }

        $override = $settings->getHtmlSanitizeStrategy();
        if ($override === HtmlSanitizeStrategy::Inherit || $override === '') {
            return $this->config->htmlSanitizeStrategy;
        }

        return HtmlSanitizeStrategy::tryFrom($override) ?? $this->config->htmlSanitizeStrategy;
    }

    private function resolveRateLimit(): int
    {
        $settings = $this->settingsProvider->findSettings();
        if (!$settings instanceof BlogSettings) {
            return $this->config->rateLimit;
        }

        $override = $settings->getCommentRateLimitLimit();

        return $override > 0 ? $override : $this->config->rateLimit;
    }

    private function resolveRateLimitInterval(): int
    {
        $settings = $this->settingsProvider->findSettings();
        if (!$settings instanceof BlogSettings) {
            return $this->config->rateLimitIntervalSeconds;
        }

        $override = $settings->getCommentRateLimitIntervalSeconds();

        return $override > 0 ? $override : $this->config->rateLimitIntervalSeconds;
    }
}
