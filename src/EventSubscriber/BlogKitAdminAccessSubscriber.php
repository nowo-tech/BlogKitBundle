<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Nowo\BlogKitBundle\Controller\Admin\BlogArticleController;
use Nowo\BlogKitBundle\Controller\Admin\BlogCommentController;
use Nowo\BlogKitBundle\Controller\Admin\BlogSettingsController;
use Nowo\BlogKitBundle\Controller\Admin\BlogTagController;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_array;
use function is_object;
use function is_string;

/**
 * Enforces blog admin access before the controller runs.
 *
 * The capability is chosen by the **controller class** (manage: articles / tags, moderate: comments, configure:
 * settings), so a routing `name_prefix` or renamed routes cannot bypass the check (SEC-004 L3). Routes of other
 * controllers named `admin_blog*` (host additions) are still guarded by name; `admin_blog_comment*` covers the
 * moderation actions (`admin_blog_comment_approve|reject|reply|delete`, SEC-004 L2). Keep a firewall /
 * `access_control` on the admin path as defence in depth.
 */
final readonly class BlogKitAdminAccessSubscriber implements EventSubscriberInterface
{
    private const string MANAGE = 'manage';

    private const string MODERATE = 'moderate';

    private const string CONFIGURE = 'configure';

    /** @var array<class-string, string> */
    private const array CONTROLLERS = [
        BlogArticleController::class  => self::MANAGE,
        BlogTagController::class      => self::MANAGE,
        BlogCommentController::class  => self::MODERATE,
        BlogSettingsController::class => self::CONFIGURE,
    ];

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
        $capability = $this->capabilityForController($event->getController())
            ?? $this->capabilityForRoute($event->getRequest()->attributes->get('_route'));

        match ($capability) {
            self::MODERATE  => $this->accessChecker->canModerate() || throw new AccessDeniedException('Blog comment moderation requires an authorized user.'),
            self::CONFIGURE => $this->accessChecker->canConfigure() || throw new AccessDeniedException('Blog settings require an authorized user.'),
            self::MANAGE    => $this->accessChecker->canManage() || throw new AccessDeniedException('Blog admin requires an authorized user.'),
            default         => null,
        };
    }

    private function capabilityForController(mixed $controller): ?string
    {
        $object = is_array($controller) ? ($controller[0] ?? null) : $controller;

        return is_object($object) ? (self::CONTROLLERS[$object::class] ?? null) : null;
    }

    private function capabilityForRoute(mixed $route): ?string
    {
        if (!is_string($route)) {
            return null;
        }

        return match (true) {
            str_starts_with($route, 'admin_blog_comment')  => self::MODERATE,
            str_starts_with($route, 'admin_blog_settings') => self::CONFIGURE,
            str_starts_with($route, 'admin_blog')          => self::MANAGE,
            default                                        => null,
        };
    }
}
