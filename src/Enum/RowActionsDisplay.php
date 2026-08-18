<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * How table/list row actions are labelled (REQ-UI-001).
 *
 * Distinct from {@see IconSet}: icon_set is how glyphs are drawn;
 * this enum is whether the cluster shows icon, text, or both.
 */
enum RowActionsDisplay: string
{
    case Icon     = 'icon';
    case Text     = 'text';
    case IconText = 'icon_text';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
