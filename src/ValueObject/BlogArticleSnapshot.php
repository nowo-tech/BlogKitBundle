<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogArticle;

/**
 * Since 1.9.0 (REQ-ARCH-001 rules 1 and 4): what the host ports and events see of an article, copied when they are
 * called: ids, slug, state, dates, titles and the ids of its author and last editor. Never an entity, a collection or a
 * user object.
 */
final readonly class BlogArticleSnapshot
{
    /**
     * @param array<string, string> $titles locale => title (translations that have one)
     * @param list<string> $tagSlugs
     * @param bool $createdByUnreadable audit ON-M1: the article has an owner whose stored id the bundle cannot read
     *                                  (`createdById` is then null, but the article is NOT unowned: deny it)
     */
    public function __construct(
        public ?int $id,
        public string $slug,
        public bool $published,
        public ?DateTimeImmutable $publishedAt,
        public ?string $createdById,
        public ?string $updatedById,
        public array $titles = [],
        public array $tagSlugs = [],
        public int $position = 0,
        public bool $createdByUnreadable = false,
    ) {
    }

    public static function of(BlogArticle $article): self
    {
        $titles = [];
        foreach ($article->getTranslations() as $translation) {
            $title = $translation->getTitle();
            if ($title !== '') {
                $titles[$translation->getLocale()] = $title;
            }
        }

        $tagSlugs = [];
        foreach ($article->getTags() as $tag) {
            $tagSlugs[] = $tag->getSlug();
        }

        return new self(
            $article->getId(),
            $article->getSlug(),
            $article->isPublished(),
            $article->getPublishedAt(),
            BlogUserRef::idOf($article->getCreatedBy()),
            BlogUserRef::idOf($article->getUpdatedBy()),
            $titles,
            $tagSlugs,
            $article->getPosition(),
            $article->getCreatedBy() instanceof UnreadableBlogUserRef,
        );
    }
}
