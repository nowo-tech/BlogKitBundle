<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use LogicException;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;

use function sprintf;
use function str_starts_with;

/**
 * Since 1.12.0 (the private entity manager, the only mode without `doctrine.entity_manager` since 2.0, with
 * `doctrine.user_reference: entity`): before a flush, a blog row whose to-one association to one of your entities (author, moderator: `BlogUserInterface`) holds an
 * instance the flushing manager does not know — the security user your manager loaded — gets a reference of the
 * flushing manager to the same row instead. Without it the private manager would take your user for a new entity
 * ("A new entity was found through the relationship"), since only your manager knows that instance.
 *
 * Only the blog's own entities are looked at; an association already holding an entity of the flushing manager and a
 * blog-to-blog association are left alone. Nothing is flushed, cleared or detached, and your user entity itself is
 * never written by the bundle.
 *
 * Since 2.0 (lesson XS-I2) a user of yours that has no identifier yet (persisted on your manager but not flushed, or
 * never persisted) fails the blog write with a {@see LogicException} that says so, before anything is written, instead
 * of Doctrine's misleading "A new entity was found through the relationship"; your manager keeps it pending.
 *
 * Registered in the private mode only; stateless (safe in a FrankenPHP worker with the kernel reset off).
 *
 * @internal registered by the bundle; not an extension point
 */
final readonly class BlogKitPrivateUserReferenceListener
{
    private const string ENTITY_NAMESPACE = 'Nowo\\BlogKitBundle\\Entity\\';

    public function preFlush(PreFlushEventArgs $args): void
    {
        $manager    = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();

        foreach ($unitOfWork->getIdentityMap() as $rootClass => $entities) {
            if (!str_starts_with($rootClass, self::ENTITY_NAMESPACE)) {
                continue;
            }

            foreach ($entities as $entity) {
                if (!$unitOfWork->isUninitializedObject($entity)) {
                    $this->swap($manager, $entity);
                }
            }
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (str_starts_with(PrivateManagerRegistry::realClass($entity::class), self::ENTITY_NAMESPACE)) {
                $this->swap($manager, $entity);
            }
        }
    }

    private function swap(EntityManagerInterface $manager, object $entity): void
    {
        $metadata = $manager->getClassMetadata($entity::class);
        foreach ($metadata->getAssociationNames() as $field) {
            if (!$metadata->isSingleValuedAssociation($field) || str_starts_with($metadata->getAssociationTargetClass($field), self::ENTITY_NAMESPACE)) {
                continue;
            }

            $value = $metadata->getFieldValue($entity, $field);
            if ($value === null || $manager->contains($value) || $manager->getUnitOfWork()->isScheduledForInsert($value)) {
                continue;
            }

            $target = $manager->getClassMetadata(PrivateManagerRegistry::realClass($value::class));
            $id     = $target->getIdentifierValues($value);
            if ($id === []) {
                throw new LogicException(sprintf('The %s "%s" of a %s has no identifier yet: save (flush) your user before the blog writes for it; the blog entity manager only stores a reference to a user row that exists. Nothing was written.', $target->getName(), $field, $metadata->getName()));
            }

            $metadata->setFieldValue($entity, $field, $manager->getReference($target->getName(), $id));
        }
    }
}
