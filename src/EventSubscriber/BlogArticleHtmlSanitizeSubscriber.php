<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Security\BlogProtection;

use function is_string;

/**
 * Sanitizes article translation HTML on persist/update.
 */
final readonly class BlogArticleHtmlSanitizeSubscriber
{
    public function __construct(
        private BlogProtection $protection,
    ) {
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $this->sanitize($args->getObject());
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$args instanceof PreUpdateEventArgs) {
            $this->sanitize($entity);

            return;
        }

        // In preUpdate the change set is already computed: changing the entity is NOT persisted, the new value must
        // go through PreUpdateEventArgs::setNewValue() (SEC-004 L4).
        if (!$entity instanceof BlogArticleTranslation || !$args->hasChangedField('body')) {
            return;
        }

        $body = $args->getNewValue('body');
        if (!is_string($body) || $body === '') {
            return;
        }

        $clean = $this->protection->htmlSanitizer()->sanitize($body);
        $args->setNewValue('body', $clean);
        $entity->setBody($clean);
    }

    private function sanitize(object $entity): void
    {
        if (!$entity instanceof BlogArticleTranslation) {
            return;
        }

        $body = $entity->getBody();
        if ($body === null || $body === '') {
            return;
        }

        $entity->setBody($this->protection->htmlSanitizer()->sanitize($body));
    }
}
