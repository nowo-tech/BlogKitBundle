<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_push;
use function array_slice;
use function count;
use function is_array;
use function max;

/**
 * Detects newly published articles and dispatches {@see BlogArticlePublishedEvent}.
 *
 * The buffer only lives between `onFlush` and `postFlush` of one flush: a failed flush never
 * reaches `postFlush`, so the buffer is dropped at the start of the next top-level flush.
 *
 * Since 1.10.0 (REQ-RUNTIME-003 rule 1): while a unit of the bundle's {@see BlogKitTransactionRunner} is open, the
 * flush runs inside the unit's transaction, so `postFlush` is not yet the commit. The articles are then held
 * ({@see hold()}) and dispatched by {@see release()} once the unit committed, or dropped by {@see discard()} when it
 * rolled back. A flush outside a unit dispatches at `postFlush`, as before.
 */
final class BlogArticlePublishedDoctrineSubscriber implements ResetInterface
{
    /** @var list<BlogArticle> */
    private array $pending = [];

    private bool $flushing = false;

    /** @var list<BlogArticle> articles flushed inside an open unit, waiting for its commit */
    private array $held = [];

    /** Open units of the bundle's runner (nested units only release at the outermost one). */
    private int $holds = 0;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function reset(): void
    {
        $this->pending  = [];
        $this->flushing = false;
        $this->held     = [];
        $this->holds    = 0;
    }

    /**
     * Since 1.10.0: a unit of the bundle's runner starts; returns the marker to pass to {@see discard()}.
     */
    public function hold(): int
    {
        ++$this->holds;

        return count($this->held);
    }

    /**
     * Since 1.10.0: the unit committed. The outermost one dispatches every held article.
     */
    public function release(): void
    {
        $this->holds = max(0, $this->holds - 1);

        if ($this->holds > 0 || $this->held === []) {
            return;
        }

        $articles   = $this->held;
        $this->held = [];
        $this->dispatchAll($articles);
    }

    /**
     * Since 1.10.0: the unit rolled back: its articles are never announced.
     *
     * @param int $marker the value {@see hold()} returned for this unit
     */
    public function discard(int $marker): void
    {
        $this->holds = max(0, $this->holds - 1);
        $this->held  = array_slice($this->held, 0, $marker);
    }

    public function onFlush(OnFlushEventArgs $onFlushEventArgs): void
    {
        if ($this->flushing) {
            return;
        }

        $this->pending = [];

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

        $articles      = $this->pending;
        $this->pending = [];

        if ($this->holds > 0) {
            // Inside a unit of the bundle's runner: not committed yet.
            array_push($this->held, ...$articles);

            return;
        }

        $this->dispatchAll($articles);
    }

    /**
     * @param list<BlogArticle> $articles
     */
    private function dispatchAll(array $articles): void
    {
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
