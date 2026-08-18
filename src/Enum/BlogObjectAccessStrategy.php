<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * How admin CRUD is scoped after role checks (REQ-UI-002 object layer).
 */
enum BlogObjectAccessStrategy: string
{
    case None    = 'none';
    case Owner   = 'owner';
    case Service = 'service';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
