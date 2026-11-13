<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticlePublishedDoctrineSubscriber;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * REQ-ARCH-001 rule 7: {@see BlogArticlePublishedEvent} is dispatched after the flush committed its transaction (a
 * listener already reads the row through another statement), and never for a flush that rolled back.
 */
final class BlogArticlePublishedAfterCommitTest extends DoctrineTestCase
{
    /** @var list<array{id: ?int, inTransaction: bool, rows: int}> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BlogArticlePublishedEvent::class, function (BlogArticlePublishedEvent $event): void {
            $this->seen[] = [
                'id'            => $event->getArticleId(),
                'inTransaction' => $this->connection->isTransactionActive(),
                'rows'          => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article WHERE id = ?', [$event->getArticleId()]),
            ];
        });
        $subscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush, Events::postFlush], $subscriber);
    }

    #[Test]
    public function dispatchedAfterTheCommitWithTheSavedId(): void
    {
        $article = $this->createArticle('launch');

        self::assertSame([['id' => $article->getId(), 'inTransaction' => false, 'rows' => 1]], $this->seen);
    }

    #[Test]
    public function neverDispatchedForARolledBackFlush(): void
    {
        $this->createArticle('taken');
        $this->seen = [];
        $this->entityManager->persist(new BlogArticle()->setSlug('taken')->setPublished(true));

        try {
            $this->entityManager->flush();
            self::fail('The duplicate slug must fail the flush.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame([], $this->seen);
    }
}
