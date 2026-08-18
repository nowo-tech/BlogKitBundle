<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Enum;

use Nowo\BlogKitBundle\Enum\RowActionsDisplay;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowActionsDisplayTest extends TestCase
{
    #[Test]
    public function valuesReturnsAllBackedValuesInDeclarationOrder(): void
    {
        self::assertSame([
            'icon',
            'text',
            'icon_text',
        ], RowActionsDisplay::values());
    }
}
