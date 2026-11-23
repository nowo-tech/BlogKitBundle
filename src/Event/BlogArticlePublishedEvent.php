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
 * listeners use those and never touch the entity. Since 2.0 the entity accessor `getArticle()` (deprecated since 1.9)
 * is removed: the event no longer holds the entity at all.
 */
final class BlogArticlePublishedEvent extends Event
{
    private readonly BlogArticleSnapshot $snapshot;

    public function __construct(BlogArticle $article)
    {
        $this->snapshot = BlogArticleSnapshot::of($article);
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
