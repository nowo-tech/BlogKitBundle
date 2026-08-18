<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

enum CssFramework: string
{
    case Bootstrap  = 'bootstrap';
    case Bootstrap4 = 'bootstrap4';
    case Bootstrap5 = 'bootstrap5';
    case Tabler     = 'tabler';
    case Tailwind   = 'tailwind';
    case Foundation = 'foundation';
    case Custom     = 'custom';
    case None       = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
