<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Closure;
use Doctrine\Common\Collections\Collection;
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
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function is_array;
use function is_object;
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
 * blog write stays on the manager (audit ON-R1). The bundle's own manager (its private one, or the dedicated
 * `doctrine.entity_manager`) is then closed like after any failed flush; one of your managers (a blog entity flushed
 * through your own manager) is never closed (since 1.12.0, audit XD-3).
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
     * - One of your managers (a blog entity flushed through it, audit XD-3): it is never closed, cleared or reset. Only the blog's own work is
     *   undone (see undoBlogWork()); your own pending changes stay on it, for you to flush or clear.
     *
     * @throws AccessDeniedException always
     */
    private function refuse(EntityManagerInterface $manager): never
    {
        if ($this->isHostManager($manager)) {
            $this->undoBlogWork($manager);
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
     * `doctrine.entity_manager`. The bundle's private manager is in no registry. Only
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
     * Undoes the blog's pending work on one of your managers without closing, clearing or resetting it (audits XD-3,
     * XD2-1). Nothing of the refused write is written by a later flush, and the manager stays usable:
     *
     * - A managed blog entity with pending work (an update, a removal, an orphan removal, a changed collection) is
     *   restored, not detached: its fields and to-one associations get their original values back, each of its
     *   collections is emptied and marked uninitialized (it is read again from the database on its next use), its
     *   scheduled work is dropped, and a removed one is managed again. It stays in the identity map, so an unchanged
     *   entity pointing at it (a translation, one of your entities) is still valid.
     * - A new blog entity (scheduled for insertion) is forgotten, and unlinked from your entities: a to-one association of
     *   yours pointing at it gets its original value back (null for a new entity of yours), and it leaves your
     *   collections.
     *
     * Your own entities' other pending changes are left untouched, for your next flush. Same private properties
     * throughout ORM 3.x (the bundle requires ^3.7), as in transaction-doctrine's restorer.
     */
    private function undoBlogWork(EntityManagerInterface $manager): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $new        = [];
        $managed    = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($this->isBlogEntity($entity)) {
                $new[spl_object_id($entity)] = $entity;
            }
        }

        foreach ([...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
            if ($this->isBlogEntity($entity)) {
                $managed[spl_object_id($entity)] = $entity;
            }
        }

        foreach ([...$unitOfWork->getScheduledCollectionUpdates(), ...$unitOfWork->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null && $this->isBlogEntity($owner) && !isset($new[spl_object_id($owner)])) {
                $managed[spl_object_id($owner)] = $owner;
            }
        }

        // Your entities that may point at a new blog entity: the managed ones (loaded) and your own new ones.
        $yours = [];
        foreach ([...array_merge(...array_values($unitOfWork->getIdentityMap())), ...$unitOfWork->getScheduledEntityInsertions()] as $entity) {
            if (!$this->isBlogEntity($entity) && !$unitOfWork->isUninitializedObject($entity)) {
                $yours[spl_object_id($entity)] = $entity;
            }
        }

        $this->dropScheduledWork($unitOfWork, $new, $managed);

        foreach ($managed as $entity) {
            $metadata = $manager->getClassMetadata($entity::class);
            $original = $unitOfWork->getOriginalEntityData($entity);
            foreach (array_intersect_key($original, array_flip([...$metadata->getFieldNames(), ...$metadata->getAssociationNames()])) as $field => $value) {
                if ($value instanceof PersistentCollection) {
                    $value->unwrap()->clear();
                    $value->setDirty(false);
                    $value->setInitialized(false);
                }

                $metadata->setFieldValue($entity, $field, $value);
            }
        }

        foreach ($yours as $entity) {
            $this->unlink($manager, $entity, $new);
        }
    }

    /**
     * Drops the scheduled work of the refused blog entities: the new ones are forgotten entirely, the managed ones (a
     * removed one included) are managed again with nothing scheduled and their original data as it was before the
     * flush, and the collection changes they own are unscheduled.
     *
     * @param array<int, object> $new The new blog entities, by spl_object_id
     * @param array<int, object> $managed The managed blog entities to restore, by spl_object_id
     */
    private function dropScheduledWork(UnitOfWork $unitOfWork, array $new, array $managed): void
    {
        Closure::bind(static function (UnitOfWork $unitOfWork, array $new, array $managed): void {
            /** @var array<int, object> $new */
            /** @var array<int, object> $managed */
            $all   = $new + $managed;
            $owned = static fn (PersistentCollection $collection): bool => isset($all[spl_object_id($collection->getOwner() ?? $collection)]);
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
            $unitOfWork->scheduledForSynchronization = array_map(static fn (array $byId): array => array_diff_key($byId, $all), $unitOfWork->scheduledForSynchronization);

            // A computed change set replaced the original data with the changed values (UnitOfWork::computeChangeSet()):
            // the original value of each changed field (the old PersistentCollection of a replaced collection) goes back.
            foreach (array_keys($managed) as $id) {
                /** @var array<string, mixed> $changes [old, new] per field; the old PersistentCollection of a replaced collection */
                $changes = $unitOfWork->entityChangeSets[$id] ?? [];
                foreach ($changes as $field => $change) {
                    $unitOfWork->originalEntityData[$id][$field] = is_array($change) ? $change[0] : $change;
                }
            }

            foreach ($all as $id => $entity) {
                unset($unitOfWork->entityInsertions[$id], $unitOfWork->entityUpdates[$id], $unitOfWork->entityDeletions[$id], $unitOfWork->extraUpdates[$id], $unitOfWork->orphanRemovals[$id], $unitOfWork->entityChangeSets[$id]);
            }

            // A pre-insert identifier (sequence, UUID) put a new entity in the identity map: its entry goes too.
            $unitOfWork->identityMap = array_map(static fn (array $byId): array => array_filter($byId, static fn (object $entity): bool => !isset($new[spl_object_id($entity)])), $unitOfWork->identityMap);

            foreach (array_keys($new) as $id) {
                unset($unitOfWork->entityStates[$id], $unitOfWork->entityIdentifiers[$id], $unitOfWork->originalEntityData[$id]);
            }

            foreach (array_keys($managed) as $id) {
                // A removed entity keeps its identity-map entry: managed again.
                $unitOfWork->entityStates[$id] = UnitOfWork::STATE_MANAGED;
            }
        }, null, UnitOfWork::class)($unitOfWork, $new, $managed);
    }

    /**
     * Removes the forgotten new blog entities from one of your entities: a to-one association pointing at one gets its
     * value from before the change back (null when your entity is new), a collection loses them.
     *
     * @param array<int, object> $new
     */
    private function unlink(EntityManagerInterface $manager, object $entity, array $new): void
    {
        $metadata   = $manager->getClassMetadata($entity::class);
        $unitOfWork = $manager->getUnitOfWork();
        // The computed change set holds [old, new]; the original data already holds the new value (computeChangeSet()).
        $changes  = $unitOfWork->getEntityChangeSet($entity);
        $reverted = false;
        foreach ($metadata->getAssociationNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            if ($value instanceof Collection) {
                // unwrap(): no orphan removal scheduled, and no load of an uninitialized collection.
                $elements = $value instanceof PersistentCollection ? $value->unwrap() : $value;
                foreach ($elements->toArray() as $key => $element) {
                    if (is_object($element) && isset($new[spl_object_id($element)])) {
                        $elements->remove($key);
                    }
                }
            } elseif (is_object($value) && isset($new[spl_object_id($value)])) {
                $metadata->setFieldValue($entity, $field, $changes[$field][0] ?? null);
                $reverted = true;
            }
        }

        if ($reverted) {
            $this->rewind($unitOfWork, $entity);
        }
    }

    /**
     * Audit XD3-1: after a to-one of one of your entities was reverted, your next flush recomputes its change set and
     * replaces the stale one. So that the recomputed set still holds your own changes (a field you changed in the same
     * flush), the data the refused flush copied over is rewound: a new entity of yours gets no original data (it is
     * inserted with all its values), a managed one gets the old value of every changed field back and loses its stale
     * scheduled update (audit XD4-1: it still named the forgotten entity), so the next flush recomputes it.
     */
    private function rewind(UnitOfWork $unitOfWork, object $entity): void
    {
        Closure::bind(static function (UnitOfWork $unitOfWork, object $entity): void {
            $id = spl_object_id($entity);
            if (isset($unitOfWork->entityInsertions[$id])) {
                unset($unitOfWork->originalEntityData[$id]);

                return;
            }

            /** @var array<string, mixed> $changes [old, new] per field; the old PersistentCollection of a replaced collection */
            $changes = $unitOfWork->entityChangeSets[$id] ?? [];
            foreach ($changes as $field => $change) {
                $unitOfWork->originalEntityData[$id][$field] = is_array($change) ? $change[0] : $change;
            }

            // Audit XD4-1: the stale update still names the forgotten entity; the next flush recomputes it from scratch.
            unset($unitOfWork->entityUpdates[$id], $unitOfWork->entityChangeSets[$id]);
        }, null, UnitOfWork::class)($unitOfWork, $entity);
    }

    private function isBlogEntity(object $entity): bool
    {
        return str_starts_with(PrivateManagerRegistry::realClass($entity::class), self::ENTITY_NAMESPACE);
    }
}
