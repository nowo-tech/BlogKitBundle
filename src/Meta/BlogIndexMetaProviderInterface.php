<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Meta;

/**
 * Optional host SEO for the blog index page.
 */
interface BlogIndexMetaProviderInterface
{
    /**
     * @return array{title: string, description: string, page_key?: string}
     */
    public function meta(): array;
}
