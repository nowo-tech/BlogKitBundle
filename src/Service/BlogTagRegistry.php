<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;

/**
 * Synchronizes blog tag definitions from seed data and resolves tags by slug.
 */
final readonly class BlogTagRegistry
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BlogTagRepository $blogTagRepository,
        private BlogLocales $blogLocales,
    ) {
    }

    /**
     * @param array<string, array<string, string>> $definitions slug => locale => name
     */
    public function syncDefinitions(array $definitions): void
    {
        $locales  = $this->blogLocales->getAll();
        $fallback = $this->blogLocales->getDefault();

        foreach ($definitions as $slug => $names) {
            $tag = $this->blogTagRepository->findBySlug($slug);

            if (!$tag instanceof BlogTag) {
                $tag = new BlogTag()->setSlug($slug);
                $this->entityManager->persist($tag);
            }

            $tag->ensureTranslations($locales);

            foreach ($locales as $locale) {
                $name = $names[$locale] ?? $names[$fallback] ?? $slug;
                $tag->getTranslationOrFallback($locale, $fallback)->setName($name);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<BlogTag>
     */
    public function resolveSlugs(array $slugs): array
    {
        $tags = [];

        foreach ($slugs as $slug) {
            $tag = $this->blogTagRepository->findBySlug($slug);

            if ($tag instanceof BlogTag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
