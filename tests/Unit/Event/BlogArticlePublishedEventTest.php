<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Event;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
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

    #[Test]
    public function eventCarriesTheIdAndASnapshotCopiedWhenItIsCreated(): void
    {
        $article = new BlogArticle()->setId(12)->setSlug('launch')->setPublished(true);
        $article->setCreatedBy(new BlogUserRef('alice'));
        $event = new BlogArticlePublishedEvent($article);
        $article->setSlug('renamed-later');

        self::assertSame(12, $event->getArticleId());
        self::assertSame('launch', $event->getArticleSnapshot()->slug);
        self::assertSame('alice', $event->getArticleSnapshot()->createdById);
    }
}
