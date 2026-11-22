<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Event;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function method_exists;

final class BlogArticlePublishedEventTest extends TestCase
{
    /** Since 2.0 the entity accessor (deprecated since 1.9) is removed and the event holds no entity at all. */
    #[Test]
    public function theEntityAccessorIsGone(): void
    {
        self::assertFalse(method_exists(BlogArticlePublishedEvent::class, 'getArticle'));
        foreach (new ReflectionClass(BlogArticlePublishedEvent::class)->getProperties() as $property) {
            self::assertNotSame(BlogArticle::class, (string) $property->getType(), $property->getName());
        }
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
