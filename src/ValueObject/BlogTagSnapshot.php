<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

use Nowo\BlogKitBundle\Entity\BlogTag;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 1): what the host ports see of a tag (id, slug, names per locale).
 */
final readonly class BlogTagSnapshot
{
    /**
     * @param array<string, string> $names locale => name
     */
    public function __construct(
        public ?int $id,
        public string $slug,
        public array $names = [],
    ) {
    }

    public static function of(BlogTag $tag): self
    {
        $names = [];
        foreach ($tag->getTranslations() as $translation) {
            $names[$translation->getLocale()] = $translation->getName();
        }

        return new self($tag->getId(), $tag->getSlug(), $names);
    }
}
