<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitWorkerStateSubscriber;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function str_starts_with;

/**
 * Since 1.9.0 (`doctrine.user_reference: id`): stamps `createdBy` / `updatedBy` of the articles written in a flush with
 * the {@see BlogUserRef} of the current security user.
 *
 * In the entity mode AuditKit's blame listener stores the security user, which implements `BlogUserInterface`. In the
 * id mode the host user implements nothing of the bundle, so the article's blame setters drop it; this listener writes
 * the reference instead (an existing author is kept). Without a security user (console, Messenger) nothing changes; a
 * security user whose id cannot be kept exactly (more than 128 characters, edge whitespace, control or format
 * characters) makes the flush fail with an AccessDeniedException (audit ON-M1: fail closed); the refused blog
 * entities are detached and the manager is closed like after any failed flush (audit ON-R1).
 *
 * Registered only in the id mode; stateless (the token is read per flush).
 */
final readonly class BlogUserBlameListener
{
    private const string ENTITY_NAMESPACE = 'Nowo\\BlogKitBundle\\Entity\\';

    public function __construct(
        private BlogUsers $users,
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
     * kept-alive worker, another user) would save it under that user's name. The blog entities of the flush are
     * detached, then the manager is closed, exactly as Doctrine closes it after any failed flush: closing also drops
     * the collection changes Doctrine keeps scheduled after an exception thrown from `onFlush`, which detaching alone
     * cannot undo. The closed manager is recreated at the next request by DoctrineBundle and by
     * {@see BlogKitWorkerStateSubscriber}.
     *
     * @throws AccessDeniedException always
     */
    private function refuse(EntityManagerInterface $manager): never
    {
        $unitOfWork = $manager->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
            if (str_starts_with($entity::class, self::ENTITY_NAMESPACE)) {
                $unitOfWork->detach($entity);
            }
        }

        $manager->close();

        throw new AccessDeniedException('BlogKit: the signed-in user has no id that can be kept exactly (doctrine.user_reference: id); the article is not saved.');
    }
}
