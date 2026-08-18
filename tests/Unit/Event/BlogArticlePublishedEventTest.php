<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Event;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogArticlePublishedEventTest extends TestCase
{
    #[Test]
    public function eventReturnsThePublishedArticle(): void
    {
        $article = new BlogArticle();
        $event   = new BlogArticlePublishedEvent($article);

        self::assertSame($article, $event->getArticle());
    }
}
