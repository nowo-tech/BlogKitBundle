<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitWorkerStateSubscriber;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_diff_key;
use function array_keys;
use function array_map;
use function spl_object_id;
use function str_starts_with;

/**
 * Since 1.9.0 (`doctrine.user_reference: id`): stamps `createdBy` / `updatedBy` of the articles written in a flush with
 * the {@see BlogUserRef} of the current security user.
 *
 * In the entity mode AuditKit's blame listener stores the security user, which implements `BlogUserInterface`. In the
 * id mode the host user implements nothing of the bundle, so the article's blame setters drop it; this listener writes
 * the reference instead (an existing author is kept). Without a security user (console, Messenger) nothing changes; a
 * security user whose id cannot be kept exactly (more than 128 characters, edge whitespace, control or format
 * characters) makes the flush fail with an AccessDeniedException (audit ON-M1: fail closed); nothing of the refused
 * blog write stays on the manager (audit ON-R1). The bundle's own manager (the private one of
 * `doctrine.private_entity_manager: true`, or the dedicated `doctrine.entity_manager`) is then closed like after any
 * failed flush; one of your managers (host mode) is never closed (since 1.12.0, audit XD-3).
 *
 * Registered only in the id mode; stateless (the token is read per flush; safe in a FrankenPHP worker with the kernel
 * reset off).
 */
final readonly class BlogUserBlameListener
{
    private const string ENTITY_NAMESPACE = 'Nowo\\BlogKitBundle\\Entity\\';

    /**
     * @param ManagerRegistry|null $registry Your managers (DoctrineBundle's `doctrine`): a refused flush of one of them
     *                                       never closes it. Null: every manager is treated as yours (never closed).
     * @param string|null $dedicatedManager The `doctrine.entity_manager` the bundle owns (null: none)
     */
    public function __construct(
        private BlogUsers $users,
        private ?ManagerRegistry $registry = null,
        private ?string $dedicatedManager = null,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $user = $this->users->currentUser();
        if ($user === null) {
            return;
        }

        $current    = BlogUserRef::tryFromUser($user);
        $manager    = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if (!$entity instanceof BlogArticle) {
                continue;
            }

            // Audit ON-M1: a signed-in user whose id cannot be kept exactly must not write an article that would read
            // as unowned (any manager could then edit it): the flush is refused. The controllers refuse such users
            // before any form touches an entity; this is the backstop for other writers.
            if (!$current instanceof BlogUserRef) {
                $this->refuse($manager);
            }

            if ($entity->getCreatedBy() === null && $unitOfWork->isScheduledForInsert($entity)) {
                $entity->setCreatedBy($current);
            }

            if (!$current->equals($entity->getUpdatedBy())) {
                $entity->setUpdatedBy($current);
            }

            $unitOfWork->recomputeSingleEntityChangeSet($manager->getClassMetadata(BlogArticle::class), $entity);
        }
    }

    /**
     * Audit ON-R1: nothing of the refused write may survive on the manager, or the next flush (another request of a
     * kept-alive worker, another user) would save it under that user's name.
     *
     * - The bundle's own manager (private or dedicated): the blog entities of the flush are detached, then the manager
     *   is closed, exactly as Doctrine closes it after any failed flush: closing also drops the collection changes
     *   Doctrine keeps scheduled after an exception thrown from `onFlush`, which detaching alone cannot undo. The closed
     *   manager is replaced at the next use (transaction-doctrine's private manager, DoctrineBundle, and
     *   {@see BlogKitWorkerStateSubscriber}).
     * - One of your managers (host mode, audit XD-3): it is never closed, cleared or reset. Only the blog's own work is
     *   forgotten (see forgetBlogWork()); your own pending changes stay on it, for you to flush or clear.
     *
     * @throws AccessDeniedException always
     */
    private function refuse(EntityManagerInterface $manager): never
    {
        if ($this->isHostManager($manager)) {
            $this->forgetBlogWork($manager->getUnitOfWork());
        } else {
            $unitOfWork = $manager->getUnitOfWork();
            foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
                if ($this->isBlogEntity($entity)) {
                    $unitOfWork->detach($entity);
                }
            }

            $manager->close();
        }

        throw new AccessDeniedException('BlogKit: the signed-in user has no id that can be kept exactly (doctrine.user_reference: id); the article is not saved.');
    }

    /**
     * Whether the flushing manager is one of yours: a manager of the registry other than the dedicated
     * `doctrine.entity_manager`. The private manager of `doctrine.private_entity_manager: true` is in no registry. Only
     * called on a refusal, so looking the managers up costs nothing on a normal flush.
     */
    private function isHostManager(EntityManagerInterface $manager): bool
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return true;
        }

        return array_any(array_keys($this->registry->getManagerNames()), fn (string $name): bool => $name !== $this->dedicatedManager && $this->registry->getManager($name) === $manager);
    }

    /**
     * Forgets the blog's pending work on one of your managers without closing it: every blog entity scheduled for
     * insertion, update or removal and every blog entity owning a scheduled collection change (tags, translations) is
     * dropped from the unit of work entirely (identity map, scheduled work, change sets, orphan removals, original data),
     * and the collection changes they own are unscheduled. A later flush then neither writes the refused change nor
     * re-detects it (the dirty entities are no longer managed); a later find() reads the row again from the database.
     * Your own entities and their pending changes are left untouched. Same private properties throughout ORM 3.x (the
     * bundle requires ^3.7), as in transaction-doctrine's runner.
     */
    private function forgetBlogWork(UnitOfWork $unitOfWork): void
    {
        $entities = [];
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
            if ($this->isBlogEntity($entity)) {
                $entities[spl_object_id($entity)] = $entity;
            }
        }

        foreach ([...$unitOfWork->getScheduledCollectionUpdates(), ...$unitOfWork->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null && $this->isBlogEntity($owner)) {
                $entities[spl_object_id($owner)] = $owner;
            }
        }

        Closure::bind(static function (UnitOfWork $unitOfWork, array $entities): void {
            /** @var array<int, object> $entities */
            $owned = static fn (PersistentCollection $collection): bool => isset($entities[spl_object_id($collection->getOwner() ?? $collection)]);
            foreach ($unitOfWork->collectionUpdates as $id => $collection) {
                if ($owned($collection)) {
                    unset($unitOfWork->collectionUpdates[$id], $unitOfWork->visitedCollections[$id], $unitOfWork->pendingCollectionElementRemovals[$id]);
                }
            }

            foreach ($unitOfWork->collectionDeletions as $id => $collection) {
                if ($owned($collection)) {
                    unset($unitOfWork->collectionDeletions[$id], $unitOfWork->visitedCollections[$id], $unitOfWork->pendingCollectionElementRemovals[$id]);
                }
            }

            // Deferred-explicit change tracking only (none of the blog entities uses it; kept for a host that remaps them).
            $unitOfWork->scheduledForSynchronization = array_map(static fn (array $byId): array => array_diff_key($byId, $entities), $unitOfWork->scheduledForSynchronization);

            foreach ($entities as $id => $entity) {
                // The identity-map entry first: removeFromIdentityMap() reads the identifier.
                if ($unitOfWork->isInIdentityMap($entity)) {
                    $unitOfWork->removeFromIdentityMap($entity);
                }

                unset(
                    $unitOfWork->entityInsertions[$id],
                    $unitOfWork->entityUpdates[$id],
                    $unitOfWork->entityDeletions[$id],
                    $unitOfWork->extraUpdates[$id],
                    $unitOfWork->orphanRemovals[$id],
                    $unitOfWork->entityChangeSets[$id],
                    $unitOfWork->entityStates[$id],
                    $unitOfWork->entityIdentifiers[$id],
                    $unitOfWork->originalEntityData[$id],
                );
            }
        }, null, UnitOfWork::class)($unitOfWork, $entities);
    }

    private function isBlogEntity(object $entity): bool
    {
        return str_starts_with(PrivateManagerRegistry::realClass($entity::class), self::ENTITY_NAMESPACE);
    }
}
