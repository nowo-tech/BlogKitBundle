<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticleHtmlSanitizeSubscriber;
use Nowo\BlogKitBundle\Tests\Support\BlogProtectionTestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogArticleHtmlSanitizeSubscriberTest extends TestCase
{
    #[Test]
    public function sanitizesTranslationBodyOnPersistAndUpdate(): void
    {
        $subscriber = new BlogArticleHtmlSanitizeSubscriber(
            BlogProtectionTestFactory::create(['htmlStrategy' => HtmlSanitizeStrategy::Strip]),
        );
        $translation = (new BlogArticleTranslation())->setBody('<p>Hello</p>');
        $args        = new LifecycleEventArgs($translation, $this->createMock(ObjectManager::class));

        $subscriber->prePersist($args);
        self::assertSame('Hello', $translation->getBody());

        $translation->setBody('<strong>Again</strong>');
        $subscriber->preUpdate($args);
        self::assertSame('Again', $translation->getBody());
    }

    #[Test]
    public function ignoresNonTranslationsAndEmptyBodies(): void
    {
        $subscriber = new BlogArticleHtmlSanitizeSubscriber(
            BlogProtectionTestFactory::create(['htmlStrategy' => HtmlSanitizeStrategy::Strip]),
        );
        $manager = $this->createMock(ObjectManager::class);

        $subscriber->prePersist(new LifecycleEventArgs(new BlogTag(), $manager));

        $empty = (new BlogArticleTranslation())->setBody('');
        $subscriber->prePersist(new LifecycleEventArgs($empty, $manager));
        self::assertSame('', $empty->getBody());

        $null = new BlogArticleTranslation();
        $subscriber->preUpdate(new LifecycleEventArgs($null, $manager));
        self::assertNull($null->getBody());
    }
}
