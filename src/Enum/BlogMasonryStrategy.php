<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * Public blog index card layout.
 */
enum BlogMasonryStrategy: string
{
    case Masonry = 'masonry';
    case Grid    = 'grid';
    case List    = 'list';

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
        return 'blog_settings.masonry_strategy.choices.' . $this->value;
    }

    /**
     * Admin choices (`inherit` keeps YAML `listing.masonry.strategy`).
     *
     * @return array<string, string>
     */
    public static function adminChoices(): array
    {
        $choices = [
            'blog_settings.masonry_strategy.choices.inherit' => self::Inherit,
        ];

        foreach (self::cases() as $case) {
            $choices[$case->labelKey()] = $case->value;
        }

        return $choices;
    }
}
