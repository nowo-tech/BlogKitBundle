<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Locale;

use function in_array;

/**
 * Config-backed locale catalog for blog entities and queries.
 */
final readonly class BlogLocales
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        private string $defaultLocale,
        private array $locales,
    ) {
    }

    public function getDefault(): string
    {
        return $this->defaultLocale;
    }

    /** @return list<string> */
    public function getAll(): array
    {
        return $this->locales;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }
}
