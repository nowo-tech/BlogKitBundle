<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\IconSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IconSetTest extends TestCase
{
    #[Test]
    public function valuesReturnsAllBackedValuesInDeclarationOrder(): void
    {
        self::assertSame([
            'bootstrap-icons',
            'tabler-icons',
            'ux_icon',
            'svg_inline',
            'none',
        ], IconSet::values());
    }
}
