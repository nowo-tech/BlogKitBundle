<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * Public comment CAPTCHA / bot-mitigation strategies.
 */
enum CommentCaptchaStrategy: string
{
    case None        = 'none';
    case Honeypot    = 'honeypot';
    case RecaptchaV2 = 'recaptcha_v2';
    case RecaptchaV3 = 'recaptcha_v3';
    case Hcaptcha    = 'hcaptcha';
    case Turnstile   = 'turnstile';
    case Service     = 'service';

    public const string Inherit = 'inherit';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function labelKey(): string
    {
        return 'blog_settings.comment_captcha_strategy.choices.' . $this->value;
    }

    /**
     * Admin choices (YAML `service` is host-only).
     *
     * @return array<string, string>
     */
    public static function adminChoices(): array
    {
        $choices = [
            'blog_settings.comment_captcha_strategy.choices.inherit' => self::Inherit,
        ];

        foreach (self::cases() as $case) {
            if ($case === self::Service) {
                continue;
            }

            $choices[$case->labelKey()] = $case->value;
        }

        return $choices;
    }
}
