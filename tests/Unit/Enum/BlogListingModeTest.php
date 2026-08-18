<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\BlogListingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogListingModeTest extends TestCase
{
    #[Test]
    public function casesExposeExpectedValuesAndLabels(): void
    {
        self::assertSame('paginated', BlogListingMode::Paginated->value);
        self::assertSame('blog_settings.listing_mode.choices.paginated', BlogListingMode::Paginated->labelKey());
        self::assertSame('infinite', BlogListingMode::Infinite->value);
        self::assertSame('blog_settings.listing_mode.choices.infinite', BlogListingMode::Infinite->labelKey());
    }
}
