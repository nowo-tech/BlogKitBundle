<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;

/**
 * Synchronizes blog tag definitions from seed data and resolves tags by slug.
 */
final readonly class BlogTagRegistry
{
    private BlogKitTransactionRunner $transactionRunner;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BlogTagRepository $blogTagRepository,
        private BlogLocales $blogLocales,
        ?BlogKitTransactionRunner $transactionRunner = null,
    ) {
        $this->transactionRunner = $transactionRunner ?? BlogKitTransactionRunner::unmanaged($entityManager);
    }

    /**
     * Since 1.10.0 (REQ-RUNTIME-003 rule 4): one unit for all the definitions. The slug is unique: when a concurrent
     * writer creates the same tag between the lookup and the insert, the unit is run once more and updates the
     * winner's tag (no 500, no duplicate).
     *
     * @param array<string, array<string, string>> $definitions slug => locale => name
     */
    public function syncDefinitions(array $definitions): void
    {
        $locales  = $this->blogLocales->getAll();
        $fallback = $this->blogLocales->getDefault();

        // @igor-ignore - The closure runs at once inside the unit; the runner keeps no reference to it.
        $this->transactionRunner->runRereadingOnDuplicate(function () use ($definitions, $locales, $fallback): void {
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
        });
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
