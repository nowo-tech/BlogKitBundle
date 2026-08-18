<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Nowo\BlogKitBundle\Locale\BlogLocales;

final class LocaleTestSupport
{
    public static function create(): BlogLocales
    {
        return new BlogLocales('es', ['es', 'en']);
    }

    public static function bindDefaults(): void
    {
        // Locales are injected; kept so existing setUp() calls remain valid.
    }
}
