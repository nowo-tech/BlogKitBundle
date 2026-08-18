<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_array;

/**
 * Detects newly published articles and dispatches {@see BlogArticlePublishedEvent}.
 */
final class BlogArticlePublishedDoctrineSubscriber
{
    /** @var list<BlogArticle> */
    private array $pending = [];

    private bool $flushing = false;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function onFlush(OnFlushEventArgs $onFlushEventArgs): void
    {
        if ($this->flushing) {
            return;
        }

        $unitOfWork = $onFlushEventArgs->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof BlogArticle && $entity->isPublished()) {
                $this->pending[] = $entity;
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof BlogArticle) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($entity);

            if (!isset($changeSet['published']) || !is_array($changeSet['published'])) {
                continue;
            }

            [$wasPublished, $isPublished] = $changeSet['published'];

            if (!$wasPublished && $isPublished) {
                $this->pending[] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $postFlushEventArgs): void
    {
        unset($postFlushEventArgs);

        if ($this->pending === [] || $this->flushing) {
            return;
        }

        $articles       = $this->pending;
        $this->pending  = [];
        $this->flushing = true;

        try {
            foreach ($articles as $article) {
                $this->eventDispatcher->dispatch(new BlogArticlePublishedEvent($article));
            }
        } finally {
            $this->flushing = false;
        }
    }
}
