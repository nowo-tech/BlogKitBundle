<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\CssFramework;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CssFrameworkTest extends TestCase
{
    #[Test]
    public function valuesReturnsAllBackedValuesInDeclarationOrder(): void
    {
        self::assertSame([
            'bootstrap',
            'bootstrap4',
            'bootstrap5',
            'tabler',
            'tailwind',
            'foundation',
            'custom',
            'none',
        ], CssFramework::values());
    }
}
