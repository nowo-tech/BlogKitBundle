<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * How the article hero image is fitted in the post detail.
 */
enum BlogHeroImageMode: string
{
    case Contain = 'contain';
    case Cover   = 'cover';

    public function labelKey(): string
    {
        return match ($this) {
            self::Contain => 'blog_settings.hero_image_mode.choices.contain',
            self::Cover   => 'blog_settings.hero_image_mode.choices.cover',
        };
    }
}
