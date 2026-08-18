<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Nowo\BlogKitBundle\Locale\BlogLocales;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves request locale against configured blog locales.
 */
final readonly class BlogLocalesLocaleResolver
{
    public function __construct(
        private BlogLocales $blogLocales,
    ) {
    }

    public function resolve(?Request $request): string
    {
        if (!$request instanceof Request) {
            return $this->blogLocales->getDefault();
        }

        $locale = $request->getLocale();

        return $this->blogLocales->isSupported($locale) ? $locale : $this->blogLocales->getDefault();
    }
}
