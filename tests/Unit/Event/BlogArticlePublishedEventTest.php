<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Event;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogArticlePublishedEventTest extends TestCase
{
    use CollectsDeprecations;

    #[Test]
    public function eventReturnsThePublishedArticle(): void
    {
        $article = new BlogArticle();
        $event   = new BlogArticlePublishedEvent($article);

        self::assertSame(
            ['Since nowo-tech/blog-kit-bundle 1.12.0: Calling "' . BlogArticlePublishedEvent::class . '::getArticle()" is deprecated and the method is removed in 2.0: use getArticleId() or getArticleSnapshot() (see UPGRADING.md "To 1.9.0").'],
            self::deprecationsOf(static fn () => self::assertSame($article, $event->getArticle())),
        );
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
