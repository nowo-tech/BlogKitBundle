<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * Public blog index listing strategy.
 */
enum BlogListingMode: string
{
    case Paginated = 'paginated';
    case Infinite  = 'infinite';

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
        return 'blog_settings.listing_mode.choices.' . $this->value;
    }

    /**
     * Admin choices (`inherit` keeps YAML `listing.mode`).
     *
     * @return array<string, string>
     */
    public static function adminChoices(): array
    {
        $choices = [
            'blog_settings.listing_mode.choices.inherit' => self::Inherit,
        ];

        foreach (self::cases() as $case) {
            $choices[$case->labelKey()] = $case->value;
        }

        return $choices;
    }
}
