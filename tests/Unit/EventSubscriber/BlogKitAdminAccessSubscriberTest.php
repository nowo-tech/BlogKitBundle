<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\EventSubscriber;

use Nowo\BlogKitBundle\EventSubscriber\BlogKitAdminAccessSubscriber;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BlogKitAdminAccessSubscriberTest extends TestCase
{
    #[Test]
    public function commentRoutesRequireModerationAccess(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->method('canModerate')->willReturn(false);

        $subscriber = new BlogKitAdminAccessSubscriber($checker);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('moderation');

        $subscriber->onKernelController($this->createControllerEvent('admin_blog_comments_index'));
    }

    #[Test]
    public function settingsRouteRequiresConfigureAccess(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->method('canConfigure')->willReturn(false);

        $subscriber = new BlogKitAdminAccessSubscriber($checker);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('settings');

        $subscriber->onKernelController($this->createControllerEvent('admin_blog_settings'));
    }

    #[Test]
    public function adminBlogRoutesRequireManageAccess(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->method('canManage')->willReturn(false);

        $subscriber = new BlogKitAdminAccessSubscriber($checker);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Blog admin');

        $subscriber->onKernelController($this->createControllerEvent('admin_blog_article_index'));
    }

    #[Test]
    public function nonAdminRoutesAreIgnored(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canManage');
        $checker->expects(self::never())->method('canModerate');
        $checker->expects(self::never())->method('canConfigure');

        $subscriber = new BlogKitAdminAccessSubscriber($checker);
        $subscriber->onKernelController($this->createControllerEvent('blog_show'));

        self::assertTrue(true);
    }

    #[Test]
    public function authorizedCommentRoutesReturnEarlyWithoutFallingThrough(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canModerate')->willReturn(true);
        $checker->expects(self::never())->method('canConfigure');
        $checker->expects(self::never())->method('canManage');

        $subscriber = new BlogKitAdminAccessSubscriber($checker);
        $subscriber->onKernelController($this->createControllerEvent('admin_blog_comments_index'));

        self::assertTrue(true);
    }

    #[Test]
    public function authorizedSettingsRouteReturnsEarlyWithoutManageCheck(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canModerate');
        $checker->expects(self::once())->method('canConfigure')->willReturn(true);
        $checker->expects(self::never())->method('canManage');

        $subscriber = new BlogKitAdminAccessSubscriber($checker);
        $subscriber->onKernelController($this->createControllerEvent('admin_blog_settings'));

        self::assertTrue(true);
    }

    #[Test]
    public function sectionedSettingsRouteRequiresConfigureAccess(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->method('canConfigure')->willReturn(false);

        $subscriber = new BlogKitAdminAccessSubscriber($checker);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('settings');

        $subscriber->onKernelController($this->createControllerEvent('admin_blog_settings_listing'));
    }

    private function createControllerEvent(string $route): ControllerEvent
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $route);

        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static function (): void {
            },
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
