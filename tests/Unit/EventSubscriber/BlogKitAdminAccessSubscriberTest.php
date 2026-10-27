<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\EventSubscriber;

use Nowo\BlogKitBundle\Controller\Admin\BlogArticleController;
use Nowo\BlogKitBundle\Controller\Admin\BlogCommentController;
use Nowo\BlogKitBundle\Controller\Admin\BlogSettingsController;
use Nowo\BlogKitBundle\Controller\Admin\BlogTagController;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitAdminAccessSubscriber;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCommentActionRoutes(): iterable
    {
        foreach (['admin_blog_comment_approve', 'admin_blog_comment_reject', 'admin_blog_comment_reply', 'admin_blog_comment_delete'] as $route) {
            yield $route => [$route];
        }
    }

    #[Test]
    #[DataProvider('provideCommentActionRoutes')]
    public function commentActionRoutesRequireModerationNotManage(string $route): void
    {
        // SEC-004 L2: the action routes are admin_blog_comment_* (singular) and used to fall through to canManage().
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canModerate')->willReturn(false);
        $checker->expects(self::never())->method('canManage');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('moderation');

        new BlogKitAdminAccessSubscriber($checker)->onKernelController($this->createControllerEvent($route));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function provideControllers(): iterable
    {
        yield 'articles' => [BlogArticleController::class, 'index', 'canManage'];
        yield 'tags' => [BlogTagController::class, 'index', 'canManage'];
        yield 'comments' => [BlogCommentController::class, 'index', 'canModerate'];
        yield 'settings' => [BlogSettingsController::class, 'settings', 'canConfigure'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('provideControllers')]
    public function theControllerClassDecidesWhateverTheRouteName(string $class, string $method, string $capability): void
    {
        // SEC-004 L3: a routing name_prefix (here "app_") used to make every admin route skip the check.
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->expects(self::once())->method($capability)->willReturn(false);

        $controller = new ReflectionClass($class)->newInstanceWithoutConstructor();

        $this->expectException(AccessDeniedException::class);

        new BlogKitAdminAccessSubscriber($checker)->onKernelController($this->createControllerEvent('app_renamed_route', [$controller, $method]));
    }

    #[Test]
    public function aStringControllerFallsBackToTheRouteName(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canManage')->willReturn(true);

        new BlogKitAdminAccessSubscriber($checker)->onKernelController($this->createControllerEvent('admin_blog_index', [self::class, 'provideControllers']));
        $this->addToAssertionCount(1);
    }

    private function createControllerEvent(string $route, ?callable $controller = null): ControllerEvent
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $route);

        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            $controller ?? static function (): void {
            },
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
