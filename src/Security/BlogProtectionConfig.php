<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security;

use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;

/**
 * YAML-backed defaults for comment protection and HTML sanitizing.
 */
final readonly class BlogProtectionConfig
{
    public function __construct(
        public CommentRateLimitStrategy $rateLimitStrategy,
        public int $rateLimit,
        public int $rateLimitIntervalSeconds,
        public ?string $rateLimitService,
        public CommentCaptchaStrategy $captchaStrategy,
        public string $captchaSiteKey,
        public string $captchaSecretKey,
        public float $captchaMinScore,
        public string $honeypotField,
        public ?string $captchaService,
        public HtmlSanitizeStrategy $htmlSanitizeStrategy,
        public ?string $htmlSanitizeService,
    ) {
    }
}
