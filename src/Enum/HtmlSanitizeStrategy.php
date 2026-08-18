<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * Article HTML sanitizer strategies (persisted body + public render).
 */
enum HtmlSanitizeStrategy: string
{
    case None      = 'none';
    case Strip     = 'strip';
    case Allowlist = 'allowlist';
    case Service   = 'service';

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
        return 'blog_settings.html_sanitize_strategy.choices.' . $this->value;
    }

    /**
     * Admin choices (YAML `service` is host-only).
     *
     * @return array<string, string>
     */
    public static function adminChoices(): array
    {
        $choices = [
            'blog_settings.html_sanitize_strategy.choices.inherit' => self::Inherit,
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
