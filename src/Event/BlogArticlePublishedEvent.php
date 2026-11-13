<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Event;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a blog article becomes published (insert or unpublished→published), after the flush that saved it
 * (REQ-ARCH-001 rule 7).
 *
 * Since 1.9.0 (rule 4) it carries the article id and a {@see BlogArticleSnapshot} copied when the event is created;
 * listeners of DDD hosts use those and never touch the entity.
 */
final class BlogArticlePublishedEvent extends Event
{
    private readonly BlogArticleSnapshot $snapshot;

    public function __construct(
        private readonly BlogArticle $article,
    ) {
        $this->snapshot = BlogArticleSnapshot::of($article);
    }

    /**
     * @deprecated since 1.9.0, removed in 2.0: use {@see getArticleId()} / {@see getArticleSnapshot()}
     */
    public function getArticle(): BlogArticle
    {
        return $this->article;
    }

    /** Since 1.9.0: the id of the published article (null only for an article that was never saved). */
    public function getArticleId(): ?int
    {
        return $this->snapshot->id;
    }

    /** Since 1.9.0: the article as it was published (slug, titles, dates, author id). */
    public function getArticleSnapshot(): BlogArticleSnapshot
    {
        return $this->snapshot;
    }
}
