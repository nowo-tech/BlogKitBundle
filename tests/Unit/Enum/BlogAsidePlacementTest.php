<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogAsidePlacementTest extends TestCase
{
    #[Test]
    public function casesExposeExpectedValuesLabelsAndSideVisibility(): void
    {
        $expectations = [
            [BlogAsidePlacement::Off, 'off', 'blog_settings.aside_placement.choices.off', false, false],
            [BlogAsidePlacement::Left, 'left', 'blog_settings.aside_placement.choices.left', true, false],
            [BlogAsidePlacement::Right, 'right', 'blog_settings.aside_placement.choices.right', false, true],
            [BlogAsidePlacement::Both, 'both', 'blog_settings.aside_placement.choices.both', true, true],
        ];

        foreach ($expectations as [$placement, $value, $labelKey, $showsLeft, $showsRight]) {
            self::assertSame($value, $placement->value);
            self::assertSame($labelKey, $placement->labelKey());
            self::assertSame($showsLeft, $placement->showsLeft());
            self::assertSame($showsRight, $placement->showsRight());
        }
    }
}
