<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogMasonryStrategyTest extends TestCase
{
    #[Test]
    public function casesExposeExpectedValuesLabelsAndAdminChoices(): void
    {
        self::assertSame(['masonry', 'grid', 'list'], BlogMasonryStrategy::values());
        self::assertSame('masonry', BlogMasonryStrategy::Masonry->value);
        self::assertSame('blog_settings.masonry_strategy.choices.masonry', BlogMasonryStrategy::Masonry->labelKey());
        self::assertSame('grid', BlogMasonryStrategy::Grid->value);
        self::assertSame('blog_settings.masonry_strategy.choices.grid', BlogMasonryStrategy::Grid->labelKey());
        self::assertSame('list', BlogMasonryStrategy::List->value);
        self::assertSame('blog_settings.masonry_strategy.choices.list', BlogMasonryStrategy::List->labelKey());

        $choices = BlogMasonryStrategy::adminChoices();
        self::assertSame('inherit', $choices['blog_settings.masonry_strategy.choices.inherit']);
        self::assertSame('masonry', $choices[BlogMasonryStrategy::Masonry->labelKey()]);
        self::assertSame('grid', $choices[BlogMasonryStrategy::Grid->labelKey()]);
        self::assertSame('list', $choices[BlogMasonryStrategy::List->labelKey()]);
    }
}
