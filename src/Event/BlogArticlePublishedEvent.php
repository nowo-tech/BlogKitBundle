<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Event;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a blog article becomes published (insert or unpublished→published).
 */
final class BlogArticlePublishedEvent extends Event
{
    public function __construct(
        private readonly BlogArticle $article,
    ) {
    }

    public function getArticle(): BlogArticle
    {
        return $this->article;
    }
}
