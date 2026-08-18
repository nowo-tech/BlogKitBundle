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

    public function labelKey(): string
    {
        return match ($this) {
            self::Paginated => 'blog_settings.listing_mode.choices.paginated',
            self::Infinite  => 'blog_settings.listing_mode.choices.infinite',
        };
    }
}
