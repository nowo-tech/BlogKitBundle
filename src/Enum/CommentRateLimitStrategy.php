<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * Public comment POST rate-limit strategies.
 */
enum CommentRateLimitStrategy: string
{
    case None          = 'none';
    case FixedWindow   = 'fixed_window';
    case PerIpArticle  = 'per_ip_article';
    case SlidingWindow = 'sliding_window';
    case Service       = 'service';

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
        return 'blog_settings.comment_rate_limit_strategy.choices.' . $this->value;
    }

    /**
     * Admin choices (YAML `service` is host-only).
     *
     * @return array<string, string>
     */
    public static function adminChoices(): array
    {
        $choices = [
            'blog_settings.comment_rate_limit_strategy.choices.inherit' => self::Inherit,
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
