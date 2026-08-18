<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Meta;

/**
 * Optional host brand name for article page titles.
 */
interface BlogBrandNameProviderInterface
{
    public function brandName(): string;
}
