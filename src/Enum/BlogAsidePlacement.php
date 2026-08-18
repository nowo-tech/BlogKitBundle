<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * Where a blog aside widget is rendered.
 */
enum BlogAsidePlacement: string
{
    case Off   = 'off';
    case Left  = 'left';
    case Right = 'right';
    case Both  = 'both';

    public function labelKey(): string
    {
        return match ($this) {
            self::Off   => 'blog_settings.aside_placement.choices.off',
            self::Left  => 'blog_settings.aside_placement.choices.left',
            self::Right => 'blog_settings.aside_placement.choices.right',
            self::Both  => 'blog_settings.aside_placement.choices.both',
        };
    }

    public function showsLeft(): bool
    {
        return $this === self::Left || $this === self::Both;
    }

    public function showsRight(): bool
    {
        return $this === self::Right || $this === self::Both;
    }
}
