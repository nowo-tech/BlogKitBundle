<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;

/**
 * Since 1.9.0 (`doctrine.user_reference: id`): stamps `createdBy` / `updatedBy` of the articles written in a flush with
 * the {@see BlogUserRef} of the current security user.
 *
 * In the entity mode AuditKit's blame listener stores the security user, which implements `BlogUserInterface`. In the
 * id mode the host user implements nothing of the bundle, so the article's blame setters drop it; this listener writes
 * the reference instead (an existing author is kept). Without a security user (console, Messenger) nothing changes.
 *
 * Registered only in the id mode; stateless (the token is read per flush).
 */
final readonly class BlogUserBlameListener
{
    public function __construct(
        private BlogUsers $users,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $current = BlogUserRef::tryFromUser($this->users->currentUser());
        if (!$current instanceof BlogUserRef) {
            return;
        }

        $manager    = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if (!$entity instanceof BlogArticle) {
                continue;
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
}
