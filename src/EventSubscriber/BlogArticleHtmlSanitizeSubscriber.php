<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Security\BlogProtection;

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
        $this->sanitize($args->getObject());
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
