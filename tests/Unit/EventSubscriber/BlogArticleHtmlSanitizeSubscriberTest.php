<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
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
        $translation = new BlogArticleTranslation()->setBody('<p>Hello</p>');
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

        $empty = new BlogArticleTranslation()->setBody('');
        $subscriber->prePersist(new LifecycleEventArgs($empty, $manager));
        self::assertSame('', $empty->getBody());

        $null = new BlogArticleTranslation();
        $subscriber->preUpdate(new LifecycleEventArgs($null, $manager));
        self::assertNull($null->getBody());
    }

    #[Test]
    public function preUpdateWritesTheSanitizedBodyIntoTheChangeSet(): void
    {
        // SEC-004 L4: changing the entity in preUpdate is not persisted; the change set must carry the new value.
        $subscriber = new BlogArticleHtmlSanitizeSubscriber(BlogProtectionTestFactory::create(['htmlStrategy' => HtmlSanitizeStrategy::Strip]));
        $manager    = $this->createStub(EntityManagerInterface::class);

        $translation = new BlogArticleTranslation()->setBody('<b>New</b>');
        $changeSet   = ['body' => ['<p>Old</p>', '<b>New</b>']];
        $args        = new PreUpdateEventArgs($translation, $manager, $changeSet);
        $subscriber->preUpdate($args);
        self::assertSame('New', $args->getNewValue('body'));
        self::assertSame('New', $translation->getBody());

        $untouched = ['title' => ['a', 'b']];
        $subscriber->preUpdate(new PreUpdateEventArgs($translation->setBody('<i>x</i>'), $manager, $untouched));
        self::assertSame('<i>x</i>', $translation->getBody(), 'body not in the change set: nothing to do');

        $emptied = ['body' => ['<p>Old</p>', '']];
        $args    = new PreUpdateEventArgs($translation, $manager, $emptied);
        $subscriber->preUpdate($args);
        self::assertSame('', $args->getNewValue('body'));

        $tagChanges = ['slug' => ['a', 'b']];
        $subscriber->preUpdate(new PreUpdateEventArgs(new BlogTag(), $manager, $tagChanges));
        $this->addToAssertionCount(1);
    }
}
