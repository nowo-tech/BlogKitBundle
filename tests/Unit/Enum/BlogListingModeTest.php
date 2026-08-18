<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\BlogListingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogListingModeTest extends TestCase
{
    #[Test]
    public function casesExposeExpectedValuesLabelsAndAdminChoices(): void
    {
        self::assertSame(['paginated', 'infinite'], BlogListingMode::values());
        self::assertSame('paginated', BlogListingMode::Paginated->value);
        self::assertSame('blog_settings.listing_mode.choices.paginated', BlogListingMode::Paginated->labelKey());
        self::assertSame('infinite', BlogListingMode::Infinite->value);
        self::assertSame('blog_settings.listing_mode.choices.infinite', BlogListingMode::Infinite->labelKey());

        $choices = BlogListingMode::adminChoices();
        self::assertSame('inherit', $choices['blog_settings.listing_mode.choices.inherit']);
        self::assertSame('paginated', $choices[BlogListingMode::Paginated->labelKey()]);
        self::assertSame('infinite', $choices[BlogListingMode::Infinite->labelKey()]);
    }
}
