<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\BlogKitBundle\Controller\Admin\BlogArticleController;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Form\BlogArticleType;
use Nowo\BlogKitBundle\Form\BlogInlineModalType;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Tests\Support\ControllerTestHelper;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

final class BlogArticleControllerTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function indexRendersFilteredPaginatedArticles(): void
    {
        $request = Request::create('/admin/blog?title=Symfony&page=2', 'GET');
        $article = $this->createArticle(10, 'symfony-testing');

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->expects(self::once())
            ->method('findFilteredPaginated')
            ->with(['title' => 'Symfony'], 2, 12)
            ->willReturn([
                'items' => [$article],
                'total' => 1,
                'page'  => 2,
            ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/index.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['items'] === [$article]
                    && $parameters['pagination']['total'] === 1
                    && $parameters['filters'] === ['title' => 'Symfony']
                    && $parameters['filter_form'] instanceof FormView),
            )
            ->willReturn('index');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
            twig: $twig,
            pageSize: 12,
        );

        $response = $controller->index($request);

        self::assertSame('index', $response->getContent());
    }

    #[Test]
    public function newRendersFormOnGet(): void
    {
        $request = Request::create('/admin/blog/new', 'GET');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/form.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'admin.articles.new'
                    && $parameters['form'] instanceof FormView),
            )
            ->willReturn('new');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createArticleFormFactory($form),
            twig: $twig,
        );

        $response = $controller->new($request);

        self::assertSame('new', $response->getContent());
    }

    #[Test]
    public function newPersistsValidArticleAndRedirects(): void
    {
        $request = Request::create('/admin/blog/new', 'POST');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(BlogArticle::class));
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createArticleFormFactory($form),
        );

        $response = $controller->new($request);

        self::assertSame('/generated/admin_blog_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.article_created'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function editRendersFormOnGet(): void
    {
        $request = Request::create('/admin/blog/5/edit', 'GET');
        $article = $this->createArticle(5, 'existing-article');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/form.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'admin.articles.edit'
                    && $parameters['form'] instanceof FormView
                    && $parameters['article'] === $article),
            )
            ->willReturn('edit');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createArticleFormFactory($form),
            twig: $twig,
        );

        $response = $controller->edit($article, $request);

        self::assertSame('edit', $response->getContent());
    }

    #[Test]
    public function editFlushesValidArticleAndRedirects(): void
    {
        $request = Request::create('/admin/blog/5/edit', 'POST');
        $article = $this->createArticle(5, 'existing-article');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createArticleFormFactory($form),
        );

        $response = $controller->edit($article, $request);

        self::assertSame('/generated/admin_blog_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.article_updated'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function editModalRendersInlineModal(): void
    {
        $request = Request::create('/admin/blog/4/edit-modal', 'GET');
        $request->setLocale('es');
        $article = $this->createArticle(4, 'inline-edit');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'createView' => new FormView(),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/_modal_form.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['form'] instanceof FormView
                    && $parameters['locale'] === 'es'),
            )
            ->willReturn('modal');

        $controller = $this->createController(
            request: $request,
            formFactory: $this->createArticleFormFactory($this->createMock(FormInterface::class), $form),
            twig: $twig,
        );

        $response = $controller->editModal($article, $request);

        self::assertSame('modal', $response->getContent());
    }

    #[Test]
    public function inlineUpdateReturnsUnprocessableEntityForInvalidForm(): void
    {
        $request = Request::create('/admin/blog/4/inline', 'POST');
        $request->setLocale('es');
        $article = $this->createArticle(4, 'inline-edit');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/_modal_form.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['form'] instanceof FormView
                    && $parameters['locale'] === 'es'),
            )
            ->willReturn('modal-invalid');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createArticleFormFactory($this->createMock(FormInterface::class), $form),
            twig: $twig,
        );

        $response = $controller->inlineUpdate($article, $request);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('modal-invalid', $response->getContent());
    }

    #[Test]
    public function inlineUpdateFlushesAndRedirectsToReferer(): void
    {
        $request = Request::create('/admin/blog/4/inline', 'POST');
        $request->headers->set('Referer', '/admin/blog?page=3');
        $article = $this->createArticle(4, 'inline-edit');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createArticleFormFactory($this->createMock(FormInterface::class), $form),
        );

        $response = $controller->inlineUpdate($article, $request);

        self::assertSame('/admin/blog?page=3', $response->headers->get('Location'));
        self::assertSame(['admin.flash.article_updated'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function inlineUpdateFlushesAndRedirectsToPublicIndexWithoutReferer(): void
    {
        $request = Request::create('/admin/blog/4/inline', 'POST');
        $article = $this->createArticle(4, 'inline-edit');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createArticleFormFactory($this->createMock(FormInterface::class), $form),
        );

        $response = $controller->inlineUpdate($article, $request);

        self::assertSame('/generated/blog_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.article_updated'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function deleteRemovesArticleWhenCsrfFormIsValid(): void
    {
        $request = Request::create('/admin/blog/7/delete', 'POST');
        $article = $this->createArticle(7, 'delete-me');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($article);
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($form),
        );

        $response = $controller->delete($article, $request);

        self::assertSame('/generated/admin_blog_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.article_deleted'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function deleteRejectsInvalidCsrfForm(): void
    {
        $request = Request::create('/admin/blog/7/delete', 'POST');
        $article = $this->createArticle(7, 'delete-me');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');
        $entityManager->expects(self::never())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($form),
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $controller->delete($article, $request);
    }

    private function createController(
        ?Request $request = null,
        ?BlogArticleRepository $repository = null,
        ?EntityManagerInterface $entityManager = null,
        ?CsrfOnlyFormFactory $csrfOnlyFormFactory = null,
        ?GetFilterFormFactory $filterFormFactory = null,
        ?FormFactoryInterface $formFactory = null,
        ?Environment $twig = null,
        int $pageSize = 20,
    ): BlogArticleController {
        $request ??= Request::create('/admin/blog', 'GET');

        $controller = new BlogArticleController(
            $repository ?? $this->createMock(BlogArticleRepository::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $csrfOnlyFormFactory ?? ControllerTestHelper::csrfOnlyFormFactory(),
            $filterFormFactory ?? ControllerTestHelper::filterFormFactory(),
            LocaleTestSupport::create(),
            $pageSize,
        );

        ControllerTestHelper::bind($controller, $request, array_filter([
            'twig'         => $twig,
            'form.factory' => $formFactory,
        ]));

        return $controller;
    }

    private function createArticleFormFactory(FormInterface $articleForm, ?FormInterface $inlineForm = null): FormFactoryInterface
    {
        $inlineForm ??= $articleForm;

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturnCallback(
            static function (string $type, mixed $data = null, array $options = []) use ($articleForm, $inlineForm): FormInterface {
                unset($data, $options);

                if ($type === BlogArticleType::class) {
                    return $articleForm;
                }

                if ($type === BlogInlineModalType::class) {
                    return $inlineForm;
                }

                throw new InvalidArgumentException('Unexpected form type: ' . $type);
            },
        );

        return $formFactory;
    }

    private function createCsrfOnlyFormFactory(FormInterface $form): CsrfOnlyFormFactory
    {
        $factory = $this->createMock(CsrfOnlyFormFactory::class);
        $factory->method('createNamed')->willReturn($form);

        return $factory;
    }

    private function createArticle(int $id, string $slug): BlogArticle
    {
        $article = (new BlogArticle())
            ->setSlug($slug)
            ->ensureTranslations();

        $translation = $article->getTranslation('es');
        if ($translation !== null) {
            $translation->setTitle('Article ' . $id)->setBody('Body');
        }

        $reflection = new ReflectionProperty(BlogArticle::class, 'id');
        $reflection->setValue($article, $id);

        return $article;
    }
}
