<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\BlogObjectAccessStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogObjectAccessStrategyTest extends TestCase
{
    #[Test]
    public function casesExposeExpectedValues(): void
    {
        self::assertSame(['none', 'owner', 'service'], BlogObjectAccessStrategy::values());
        self::assertSame('none', BlogObjectAccessStrategy::None->value);
        self::assertSame('owner', BlogObjectAccessStrategy::Owner->value);
        self::assertSame('service', BlogObjectAccessStrategy::Service->value);
    }
}
