<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller;

use InvalidArgumentException;
use Nowo\BlogKitBundle\Controller\PublicCommentController;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Tests\Support\BlogProtectionTestFactory;
use Nowo\BlogKitBundle\Tests\Support\ControllerTestHelper;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublicCommentControllerTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function createAddsErrorFlashWhenFormIsInvalid(): void
    {
        $request = Request::create('/blog/post/comments', 'POST');
        $article = $this->createArticle(3, 'post', true);

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'post', 'published' => true])
            ->willReturn($article);

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::never())->method('createPublicComment');

        $controller = $this->createController(
            request: $request,
            articleRepository: $repository,
            manager: $manager,
            formFactory: $this->createFormFactoryForForm($form),
        );

        $response = $controller->create('post', $request);

        self::assertSame('/generated/blog_show?slug=post', $response->headers->get('Location'));
        self::assertSame(['blog.comments.error_invalid'], $request->getSession()->getFlashBag()->get('error'));
    }

    #[Test]
    public function createCreatesPublicCommentAndRedirects(): void
    {
        $request = Request::create('/blog/post/comments', 'POST');
        $article = $this->createArticle(3, 'post', true);

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'post', 'published' => true])
            ->willReturn($article);

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);
        $form->method('get')->willReturnCallback(fn (string $name): FormInterface => match ($name) {
            'authorName'  => $this->createField('Alice'),
            'authorEmail' => $this->createField('alice@example.test'),
            'body'        => $this->createField('Nice article'),
            default       => throw new InvalidArgumentException('Unexpected field ' . $name),
        });

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::once())
            ->method('createPublicComment')
            ->with($article, 'Alice', 'alice@example.test', 'Nice article', $request);

        $controller = $this->createController(
            request: $request,
            articleRepository: $repository,
            manager: $manager,
            formFactory: $this->createFormFactoryForForm($form),
        );

        $response = $controller->create('post', $request);

        self::assertSame('/generated/blog_show?slug=post', $response->headers->get('Location'));
        self::assertSame(['blog.comments.success_pending'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function createThrowsNotFoundWhenPublishedArticleDoesNotExist(): void
    {
        $request    = Request::create('/blog/missing/comments', 'POST');
        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'missing', 'published' => true])
            ->willReturn(null);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('blog.article.not_found')
            ->willReturn('Article not found');

        $controller = $this->createController(
            request: $request,
            articleRepository: $repository,
            translator: $translator,
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Article not found');

        $controller->create('missing', $request);
    }

    #[Test]
    public function staffReplyThrowsNotFoundWhenParentCommentDoesNotExist(): void
    {
        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())->method('find')->with(99)->willReturn(null);

        $controller = $this->createController(
            commentRepository: $repository,
        );

        $this->expectException(NotFoundHttpException::class);

        $controller->staffReply(99, Request::create('/blog/comments/99/reply', 'POST'));
    }

    #[Test]
    public function staffReplyThrowsNotFoundWhenArticleIsUnpublished(): void
    {
        $article = $this->createArticle(4, 'post', false);
        $comment = $this->createComment(9, $article);

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())->method('find')->with(9)->willReturn($comment);

        $controller = $this->createController(
            commentRepository: $repository,
        );

        $this->expectException(NotFoundHttpException::class);

        $controller->staffReply(9, Request::create('/blog/comments/9/reply', 'POST'));
    }

    #[Test]
    public function staffReplyAddsErrorFlashWhenReplyFormIsInvalid(): void
    {
        $request = Request::create('/blog/comments/9/reply', 'POST');
        $article = $this->createArticle(4, 'post', true);
        $comment = $this->createComment(9, $article);

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())->method('find')->with(9)->willReturn($comment);

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::never())->method('createStaffReply');

        $controller = $this->createController(
            request: $request,
            commentRepository: $repository,
            manager: $manager,
            formFactory: $this->createFormFactoryForForm($form),
        );

        $response = $controller->staffReply(9, $request);

        self::assertSame('/generated/blog_show?slug=post', $response->headers->get('Location'));
        self::assertSame(['blog.comments.error_reply'], $request->getSession()->getFlashBag()->get('error'));
    }

    #[Test]
    public function staffReplyDeniesAccessWhenCurrentUserIsNotABlogUser(): void
    {
        $request = Request::create('/blog/comments/9/reply', 'POST');
        $article = $this->createArticle(4, 'post', true);
        $comment = $this->createComment(9, $article);

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())->method('find')->with(9)->willReturn($comment);

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'getData'     => ['body' => 'Staff reply'],
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::never())->method('createStaffReply');

        $controller = $this->createController(
            request: $request,
            commentRepository: $repository,
            manager: $manager,
            formFactory: $this->createFormFactoryForForm($form),
        );

        $this->expectException(AccessDeniedException::class);

        $controller->staffReply(9, $request);
    }

    #[Test]
    public function staffReplyCreatesReplyAndRedirects(): void
    {
        $request = Request::create('/blog/comments/9/reply', 'POST');
        $article = $this->createArticle(4, 'post', true);
        $comment = $this->createComment(9, $article);
        $user    = new TestUser();

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())->method('find')->with(9)->willReturn($comment);

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'getData'     => ['body' => 'Staff reply'],
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $manager = $this->createMock(BlogCommentManager::class);
        $manager->expects(self::once())->method('createStaffReply')->with($comment, $user, 'Staff reply');

        $controller = $this->createController(
            request: $request,
            commentRepository: $repository,
            manager: $manager,
            formFactory: $this->createFormFactoryForForm($form),
            user: $user,
        );

        $response = $controller->staffReply(9, $request);

        self::assertSame('/generated/blog_show?slug=post', $response->headers->get('Location'));
        self::assertSame(['admin.flash.reply_published'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function staffReplyDeniesAccessWhenUserCannotModerate(): void
    {
        $request = Request::create('/blog/comments/9/reply', 'POST');
        $article = $this->createArticle(4, 'post', true);
        $comment = $this->createComment(9, $article);
        $user    = new TestUser();

        $repository = $this->createMock(BlogCommentRepository::class);
        $repository->expects(self::once())->method('find')->with(9)->willReturn($comment);

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'getData'     => ['body' => 'Staff reply'],
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $access = $this->createMock(BlogKitAccessCheckerInterface::class);
        $access->method('canModerate')->willReturn(false);

        $controller = $this->createController(
            request: $request,
            commentRepository: $repository,
            formFactory: $this->createFormFactoryForForm($form),
            user: $user,
            accessChecker: $access,
        );

        $this->expectException(AccessDeniedException::class);

        $controller->staffReply(9, $request);
    }

    private function createController(
        ?Request $request = null,
        ?BlogArticleRepository $articleRepository = null,
        ?BlogCommentRepository $commentRepository = null,
        ?BlogCommentManager $manager = null,
        ?TranslatorInterface $translator = null,
        ?FormFactoryInterface $formFactory = null,
        ?TestUser $user = null,
        ?BlogKitAccessCheckerInterface $accessChecker = null,
    ): PublicCommentController {
        $request ??= Request::create('/blog/post/comments', 'POST');

        $controller = new PublicCommentController(
            $articleRepository ?? $this->createMock(BlogArticleRepository::class),
            $commentRepository ?? $this->createMock(BlogCommentRepository::class),
            $manager ?? $this->createMock(BlogCommentManager::class),
            $translator ?? $this->createMock(TranslatorInterface::class),
            BlogProtectionTestFactory::create(['rateLimit' => 0]),
            $accessChecker ?? $this->allowingAccessChecker(),
        );

        ControllerTestHelper::bind($controller, $request, array_filter([
            'form.factory' => $formFactory,
        ]), $user);

        return $controller;
    }

    private function createFormFactoryForForm(FormInterface $form): FormFactoryInterface
    {
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturn($form);

        return $factory;
    }

    private function createField(mixed $value): FormInterface
    {
        return $this->createConfiguredMock(FormInterface::class, [
            'getData'    => $value,
            'createView' => new FormView(),
        ]);
    }

    private function createArticle(int $id, string $slug, bool $published): BlogArticle
    {
        $article = (new BlogArticle())
            ->setSlug($slug)
            ->setPublished($published);

        $reflection = new ReflectionProperty(BlogArticle::class, 'id');
        $reflection->setValue($article, $id);

        return $article;
    }

    private function createComment(int $id, BlogArticle $article): BlogComment
    {
        $comment = (new BlogComment())
            ->setArticle($article)
            ->setAuthorName('Author')
            ->setBody('Comment')
            ->setStatus(BlogCommentStatus::Approved);

        $reflection = new ReflectionProperty(BlogComment::class, 'id');
        $reflection->setValue($comment, $id);

        return $comment;
    }

    private function allowingAccessChecker(): BlogKitAccessCheckerInterface
    {
        $access = $this->createMock(BlogKitAccessCheckerInterface::class);
        $access->method('canModerate')->willReturn(true);

        return $access;
    }
}
