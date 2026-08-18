<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Enum;

/**
 * Icon set values for {@code nowo_blog_kit.web_ui.icon_set} (REQ-UI-001).
 */
enum IconSet: string
{
    case BootstrapIcons = 'bootstrap-icons';
    case TablerIcons    = 'tabler-icons';
    case UxIcon         = 'ux_icon';
    case SvgInline      = 'svg_inline';
    case None           = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
