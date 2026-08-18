<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Locale;

use Nowo\BlogKitBundle\Locale\BlogLocales;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogLocalesTest extends TestCase
{
    #[Test]
    public function instanceAccessorsReturnConfiguredValues(): void
    {
        $locales = new BlogLocales('en', ['en', 'es']);

        self::assertSame('en', $locales->getDefault());
        self::assertSame(['en', 'es'], $locales->getAll());
        self::assertTrue($locales->isSupported('en'));
        self::assertTrue($locales->isSupported('es'));
        self::assertFalse($locales->isSupported('fr'));
    }
}
