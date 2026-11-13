<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Since 1.9.0 (`doctrine.user_reference: id`): stamps `createdBy` / `updatedBy` of the articles written in a flush with
 * the {@see BlogUserRef} of the current security user.
 *
 * In the entity mode AuditKit's blame listener stores the security user, which implements `BlogUserInterface`. In the
 * id mode the host user implements nothing of the bundle, so the article's blame setters drop it; this listener writes
 * the reference instead (an existing author is kept). Without a security user (console, Messenger) nothing changes; a
 * security user whose id cannot be kept exactly (more than 128 characters, edge whitespace, control or format
 * characters) makes the flush fail with an AccessDeniedException (audit ON-M1: fail closed).
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
            // as unowned (any manager could then edit it): the flush is refused.
            if (!$current instanceof BlogUserRef) {
                throw new AccessDeniedException('BlogKit: the signed-in user has no id that can be kept exactly (doctrine.user_reference: id); the article is not saved.');
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
