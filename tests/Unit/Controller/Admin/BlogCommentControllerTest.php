<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller\Admin;

use Nowo\BlogKitBundle\Controller\Admin\BlogCommentController;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Tests\Support\ControllerTestHelper;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

final class BlogCommentControllerTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function indexRendersPendingCommentsAndBuildsActionForms(): void
    {
        $request = Request::create('/admin/blog/comments?status=pending&author=Ana', 'GET');
        $comment = $this->createComment(5, BlogCommentStatus::Pending);

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with(['author' => 'Ana'], BlogCommentStatus::Pending)
            ->willReturn([$comment]);
        $repository->expects(self::once())->method('countPending')->willReturn(3);

        $csrfFactory = $this->createMock(CsrfOnlyFormFactory::class);
        $csrfFactory->method('createNamed')->willReturn(
            $this->createViewOnlyForm(),
            $this->createViewOnlyForm(),
            $this->createViewOnlyForm(),
        );

        $replyForm   = $this->createViewOnlyForm();
        $formFactory = $this->createReplyFormFactory($replyForm);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/comments/index.html.twig',
                self::callback(static function (array $parameters) use ($comment): bool {
                    return $parameters['items'] === [$comment]
                        && $parameters['filters'] === ['author' => 'Ana']
                        && $parameters['filter'] === 'pending'
                        && $parameters['pending_count'] === 3
                        && $parameters['filter_form'] instanceof FormView
                        && isset($parameters['reply_forms'][5], $parameters['action_forms'][5]);
                }),
            )
            ->willReturn('pending');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            csrfOnlyFormFactory: $csrfFactory,
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
            formFactory: $formFactory,
            twig: $twig,
        );

        $response = $controller->index($request);

        self::assertSame('pending', $response->getContent());
    }

    #[Test]
    public function indexUsesApprovedStatusFilter(): void
    {
        $request = Request::create('/admin/blog/comments?status=approved', 'GET');

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with([], BlogCommentStatus::Approved)
            ->willReturn([]);
        $repository->expects(self::once())->method('countPending')->willReturn(0);

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
        );

        $response = $controller->index($request);

        self::assertSame('@NowoBlogKitBundle/admin/comments/index.html.twig', $response->getContent());
    }

    #[Test]
    public function indexUsesRejectedStatusFilter(): void
    {
        $request = Request::create('/admin/blog/comments?status=rejected', 'GET');

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with([], BlogCommentStatus::Rejected)
            ->willReturn([]);
        $repository->expects(self::once())->method('countPending')->willReturn(0);

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
        );

        $response = $controller->index($request);

        self::assertSame('@NowoBlogKitBundle/admin/comments/index.html.twig', $response->getContent());
    }

    #[Test]
    public function indexUsesNullStatusForAllTab(): void
    {
        $request = Request::create('/admin/blog/comments?status=all', 'GET');

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with([], null)
            ->willReturn([]);
        $repository->expects(self::once())->method('countPending')->willReturn(0);

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
        );

        $response = $controller->index($request);

        self::assertSame('@NowoBlogKitBundle/admin/comments/index.html.twig', $response->getContent());
    }

    #[Test]
    public function indexFallsBackToPendingForInvalidStatus(): void
    {
        $request = Request::create('/admin/blog/comments?status=weird', 'GET');

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with([], BlogCommentStatus::Pending)
            ->willReturn([]);
        $repository->expects(self::once())->method('countPending')->willReturn(0);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/comments/index.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['filter'] === 'pending'),
            )
            ->willReturn('fallback');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
            twig: $twig,
        );

        $response = $controller->index($request);

        self::assertSame('fallback', $response->getContent());
    }

    #[Test]
    public function indexSkipsCommentsWithoutIdentifierWhenBuildingForms(): void
    {
        $request = Request::create('/admin/blog/comments?status=pending', 'GET');
        $comment = (new BlogComment())
            ->setArticle((new BlogArticle())->setSlug('post')->setPublished(true))
            ->setAuthorName('Author')
            ->setBody('Body')
            ->setStatus(BlogCommentStatus::Pending);

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with([], BlogCommentStatus::Pending)
            ->willReturn([$comment]);
        $repository->expects(self::once())->method('countPending')->willReturn(1);

        $csrfFactory = $this->createMock(CsrfOnlyFormFactory::class);
        $csrfFactory->expects(self::never())->method('createNamed');

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects(self::never())->method('create');

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/comments/index.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['items'] === [$comment]
                    && $parameters['reply_forms'] === []
                    && $parameters['action_forms'] === []),
            )
            ->willReturn('skip-null-id');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            csrfOnlyFormFactory: $csrfFactory,
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
            formFactory: $formFactory,
            twig: $twig,
        );

        $response = $controller->index($request);

        self::assertSame('skip-null-id', $response->getContent());
    }

    #[Test]
    public function approveApprovesCommentAndPreservesReturnFilters(): void
    {
        $request = Request::create('/admin/blog/comments/5/approve', 'POST', [
            '_return_status'  => 'approved',
            '_return_author'  => 'Ana',
            '_return_article' => 'Symfony',
            '_return_body'    => 'Great',
        ]);
        $comment = $this->createComment(5, BlogCommentStatus::Pending);
        $user    = new TestUser();

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::once())->method('approve')->with($comment, $user);

        $csrfForm = $this->createSubmittedForm(true);

        $controller = $this->createController(
            request: $request,
            manager: $manager,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($csrfForm),
            user: $user,
        );

        $response = $controller->approve($comment, $request);

        self::assertSame(
            '/generated/admin_blog_comments_index?status=approved&author=Ana&article=Symfony&body=Great',
            $response->headers->get('Location'),
        );
        self::assertSame(['admin.flash.comment_approved'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function rejectUsesQueryStringFallbackForRedirect(): void
    {
        $request = Request::create('/admin/blog/comments/5/reject?status=rejected&author=John', 'POST');
        $comment = $this->createComment(5, BlogCommentStatus::Pending);
        $user    = new TestUser();

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::once())->method('reject')->with($comment, $user);

        $controller = $this->createController(
            request: $request,
            manager: $manager,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($this->createSubmittedForm(true)),
            user: $user,
        );

        $response = $controller->reject($comment, $request);

        self::assertSame('/generated/admin_blog_comments_index?status=rejected&author=John', $response->headers->get('Location'));
        self::assertSame(['admin.flash.comment_rejected'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function replyAddsErrorFlashWhenFormIsInvalid(): void
    {
        $request = Request::create('/admin/blog/comments/5/reply', 'POST');
        $comment = $this->createComment(5, BlogCommentStatus::Approved);
        $user    = new TestUser();
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::never())->method('createStaffReply');
        $manager->expects(self::never())->method('approve');

        $controller = $this->createController(
            request: $request,
            manager: $manager,
            formFactory: $this->createReplyFormFactory($form),
            user: $user,
        );

        $response = $controller->reply($comment, $request);

        self::assertSame('/generated/admin_blog_comments_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.reply_invalid'], $request->getSession()->getFlashBag()->get('error'));
    }

    #[Test]
    public function replyAutoApprovesPendingParentComment(): void
    {
        $request = Request::create('/admin/blog/comments/5/reply', 'POST');
        $comment = $this->createComment(5, BlogCommentStatus::Pending);
        $user    = new TestUser();
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'getData'     => ['body' => 'Thanks for your comment'],
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::once())->method('createStaffReply')->with($comment, $user, 'Thanks for your comment');
        $manager->expects(self::once())->method('approve')->with($comment, $user);

        $controller = $this->createController(
            request: $request,
            manager: $manager,
            formFactory: $this->createReplyFormFactory($form),
            user: $user,
        );

        $response = $controller->reply($comment, $request);

        self::assertSame('/generated/admin_blog_comments_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.reply_published'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function approveRequiresBlogUser(): void
    {
        $request = Request::create('/admin/blog/comments/5/approve', 'POST');
        $comment = $this->createComment(5, BlogCommentStatus::Pending);
        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::never())->method('approve');

        $controller = $this->createController(
            request: $request,
            manager: $manager,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($this->createSubmittedForm(true)),
        );

        $this->expectException(AccessDeniedException::class);

        $controller->approve($comment, $request);
    }

    #[Test]
    public function deleteRemovesCommentAndRedirects(): void
    {
        $request = Request::create('/admin/blog/comments/5/delete', 'POST');
        $comment = $this->createComment(5, BlogCommentStatus::Approved);

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::once())->method('delete')->with($comment);

        $controller = $this->createController(
            request: $request,
            manager: $manager,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($this->createSubmittedForm(true)),
        );

        $response = $controller->delete($comment, $request);

        self::assertSame('/generated/admin_blog_comments_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.comment_deleted'], $request->getSession()->getFlashBag()->get('success'));
    }

    private function createController(
        ?Request $request = null,
        ?BlogCommentRepository $repository = null,
        ?BlogCommentManager $manager = null,
        ?CsrfOnlyFormFactory $csrfOnlyFormFactory = null,
        ?GetFilterFormFactory $filterFormFactory = null,
        ?FormFactoryInterface $formFactory = null,
        ?Environment $twig = null,
        ?TestUser $user = null,
    ): BlogCommentController {
        $request ??= Request::create('/admin/blog/comments', 'GET');

        $controller = new BlogCommentController(
            $repository ?? $this->createMock(BlogCommentRepository::class),
            $manager ?? $this->createMock(BlogCommentManager::class),
            $csrfOnlyFormFactory ?? ControllerTestHelper::csrfOnlyFormFactory(),
            $filterFormFactory ?? ControllerTestHelper::filterFormFactory(),
        );

        ControllerTestHelper::bind($controller, $request, array_filter([
            'twig'         => $twig,
            'form.factory' => $formFactory,
        ]), $user);

        return $controller;
    }

    private function createReplyFormFactory(FormInterface $form): FormFactoryInterface
    {
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturn($form);

        return $factory;
    }

    private function createCsrfOnlyFormFactory(FormInterface $form): CsrfOnlyFormFactory
    {
        $factory = $this->createMock(CsrfOnlyFormFactory::class);
        $factory->method('createNamed')->willReturn($form);

        return $factory;
    }

    private function createSubmittedForm(bool $valid): FormInterface
    {
        return $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => $valid,
            'createView'  => new FormView(),
        ]);
    }

    private function createViewOnlyForm(): FormInterface
    {
        $form = $this->createMock(FormInterface::class);
        $form->method('createView')->willReturn(new FormView());

        return $form;
    }

    private function createComment(int $id, BlogCommentStatus $status): BlogComment
    {
        $article = (new BlogArticle())
            ->setSlug('blog-post')
            ->setPublished(true);

        $comment = (new BlogComment())
            ->setArticle($article)
            ->setAuthorName('Author')
            ->setBody('Body')
            ->setStatus($status);

        $reflection = new ReflectionProperty(BlogComment::class, 'id');
        $reflection->setValue($comment, $id);

        return $comment;
    }
}
