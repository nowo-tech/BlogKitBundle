<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommentProtectionEnumTest extends TestCase
{
    #[Test]
    public function rateLimitStrategyExposesValuesLabelsAndAdminChoices(): void
    {
        self::assertSame([
            'none',
            'fixed_window',
            'per_ip_article',
            'sliding_window',
            'service',
        ], CommentRateLimitStrategy::values());
        self::assertSame(
            'blog_settings.comment_rate_limit_strategy.choices.fixed_window',
            CommentRateLimitStrategy::FixedWindow->labelKey(),
        );

        $choices = CommentRateLimitStrategy::adminChoices();
        self::assertSame('inherit', $choices['blog_settings.comment_rate_limit_strategy.choices.inherit']);
        self::assertSame('none', $choices[CommentRateLimitStrategy::None->labelKey()]);
        self::assertNotContains(CommentRateLimitStrategy::Service->value, $choices);
    }

    #[Test]
    public function captchaStrategyExposesValuesLabelsAndAdminChoices(): void
    {
        self::assertContains('honeypot', CommentCaptchaStrategy::values());
        self::assertSame(
            'blog_settings.comment_captcha_strategy.choices.turnstile',
            CommentCaptchaStrategy::Turnstile->labelKey(),
        );
        $choices = CommentCaptchaStrategy::adminChoices();
        self::assertSame('inherit', $choices['blog_settings.comment_captcha_strategy.choices.inherit']);
        self::assertNotContains('service', $choices);
    }

    #[Test]
    public function htmlSanitizeStrategyExposesValuesLabelsAndAdminChoices(): void
    {
        self::assertSame(['none', 'strip', 'allowlist', 'service'], HtmlSanitizeStrategy::values());
        self::assertSame(
            'blog_settings.html_sanitize_strategy.choices.allowlist',
            HtmlSanitizeStrategy::Allowlist->labelKey(),
        );
        $choices = HtmlSanitizeStrategy::adminChoices();
        self::assertSame('inherit', $choices['blog_settings.html_sanitize_strategy.choices.inherit']);
        self::assertSame('strip', $choices[HtmlSanitizeStrategy::Strip->labelKey()]);
        self::assertNotContains('service', $choices);
    }
}
