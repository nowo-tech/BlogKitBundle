<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogHeroImageModeTest extends TestCase
{
    #[Test]
    public function casesExposeExpectedValuesAndLabels(): void
    {
        self::assertSame('contain', BlogHeroImageMode::Contain->value);
        self::assertSame('blog_settings.hero_image_mode.choices.contain', BlogHeroImageMode::Contain->labelKey());
        self::assertSame('cover', BlogHeroImageMode::Cover->value);
        self::assertSame('blog_settings.hero_image_mode.choices.cover', BlogHeroImageMode::Cover->labelKey());
    }
}
