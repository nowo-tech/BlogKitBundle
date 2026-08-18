<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticlePublishedDoctrineSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class BlogArticlePublishedDoctrineSubscriberTest extends TestCase
{
    #[Test]
    public function postFlushDispatchesEventForPublishedInsertions(): void
    {
        $article    = (new BlogArticle())->setPublished(true);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (object $event): bool => $event instanceof BlogArticlePublishedEvent
                    && $event->getArticle() === $article,
            ))
            ->willReturnArgument(0);

        $subscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);

        $subscriber->onFlush($this->createOnFlushEventArgs([$article]));
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    #[Test]
    public function postFlushDispatchesEventForFalseToTruePublishedUpdates(): void
    {
        $article    = (new BlogArticle())->setPublished(true);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (object $event): bool => $event instanceof BlogArticlePublishedEvent
                    && $event->getArticle() === $article,
            ))
            ->willReturnArgument(0);

        $subscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);

        $subscriber->onFlush($this->createOnFlushEventArgs([], [$article], [
            spl_object_id($article) => ['published' => [false, true]],
        ]));
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    #[Test]
    public function onFlushIgnoresNonArticleUpdates(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $subscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);
        $subscriber->onFlush($this->createOnFlushEventArgs([], [new stdClass()]));
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    #[Test]
    public function onFlushReturnsImmediatelyWhileSubscriberIsAlreadyFlushing(): void
    {
        $article    = (new BlogArticle())->setPublished(true);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $subscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);
        $this->setPrivateProperty($subscriber, 'flushing', true);

        $subscriber->onFlush($this->createOnFlushEventArgs([$article]));
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    #[Test]
    public function postFlushSkipsDispatchWhenThereAreNoPendingArticles(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $subscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);

        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     * @param array<int, array<string, mixed>> $changeSets
     */
    private function createOnFlushEventArgs(
        array $insertions,
        array $updates = [],
        array $changeSets = [],
    ): OnFlushEventArgs {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($insertions);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn($updates);
        $unitOfWork->method('getEntityChangeSet')->willReturnCallback(
            static fn (object $entity): array => $changeSets[spl_object_id($entity)] ?? [],
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $event = $this->createMock(OnFlushEventArgs::class);
        $event->method('getObjectManager')->willReturn($entityManager);

        return $event;
    }

    private function setPrivateProperty(object $object, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($object, $name);
        $property->setValue($object, $value);
    }
}
