<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_string;

/**
 * Enforces blog admin access by route name prefix.
 */
final readonly class BlogKitAdminAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BlogKitAccessCheckerInterface $accessChecker,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route)) {
            return;
        }

        if (str_starts_with($route, 'admin_blog_comments')) {
            if (!$this->accessChecker->canModerate()) {
                throw new AccessDeniedException('Blog comment moderation requires an authorized user.');
            }

            return;
        }

        if (str_starts_with($route, 'admin_blog_settings')) {
            if (!$this->accessChecker->canConfigure()) {
                throw new AccessDeniedException('Blog settings require an authorized user.');
            }

            return;
        }

        if (str_starts_with($route, 'admin_blog')) {
            if (!$this->accessChecker->canManage()) {
                throw new AccessDeniedException('Blog admin requires an authorized user.');
            }
        }
    }
}
