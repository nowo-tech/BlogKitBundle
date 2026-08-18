<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Controller\Admin\BlogTagController;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

final class BlogTagControllerTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function indexRendersFilteredTags(): void
    {
        $request = Request::create('/admin/blog/tags?slug=php', 'GET');
        $tag     = $this->createTag(3, 'php');
        $unsaved = $this->createTag(0, 'draft');
        (new ReflectionProperty(BlogTag::class, 'id'))->setValue($unsaved, null);

        $repository = $this->createMock(BlogTagRepository::class);
        $repository->expects(self::once())->method('findFiltered')->with(['slug' => 'php'])->willReturn([$tag, $unsaved]);
        $repository->expects(self::once())->method('countArticlesByTagId')->willReturn([3 => 2]);

        $deleteForm = $this->createConfiguredMock(FormInterface::class, [
            'createView' => new FormView(),
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/tags/index.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['items'] === [$tag, $unsaved]
                    && $parameters['article_counts'] === [3 => 2]
                    && $parameters['filters'] === ['slug' => 'php']
                    && $parameters['filter_form'] instanceof FormView
                    && isset($parameters['delete_forms'][3])
                    && $parameters['delete_forms'][3] instanceof FormView
                    && !isset($parameters['delete_forms'][0])),
            )
            ->willReturn('index');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($deleteForm),
            filterFormFactory: ControllerTestHelper::filterFormFactory(),
            twig: $twig,
        );

        $response = $controller->index($request);

        self::assertSame('index', $response->getContent());
    }

    #[Test]
    public function newRendersFormOnGet(): void
    {
        $request = Request::create('/admin/blog/tags/new', 'GET');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/tags/form.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'admin.tags.new'
                    && $parameters['form'] instanceof FormView),
            )
            ->willReturn('new');

        $controller = $this->createController(
            request: $request,
            formFactory: $this->createTagFormFactory($form),
            twig: $twig,
        );

        $response = $controller->new($request);

        self::assertSame('new', $response->getContent());
    }

    #[Test]
    public function newPersistsValidTagAndRedirects(): void
    {
        $request = Request::create('/admin/blog/tags/new', 'POST');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(BlogTag::class));
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            formFactory: $this->createTagFormFactory($form),
        );

        $response = $controller->new($request);

        self::assertSame('/generated/admin_blog_tags_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.tag_created'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function editRendersFormOnGet(): void
    {
        $request = Request::create('/admin/blog/tags/4/edit', 'GET');
        $tag     = $this->createTag(4, 'testing');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/tags/form.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'admin.tags.edit'
                    && $parameters['form'] instanceof FormView
                    && $parameters['tag'] === $tag),
            )
            ->willReturn('edit');

        $controller = $this->createController(
            request: $request,
            formFactory: $this->createTagFormFactory($form),
            twig: $twig,
        );

        $response = $controller->edit($tag, $request);

        self::assertSame('edit', $response->getContent());
    }

    #[Test]
    public function editFlushesValidTagAndRedirects(): void
    {
        $request = Request::create('/admin/blog/tags/4/edit', 'POST');
        $tag     = $this->createTag(4, 'testing');
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
            formFactory: $this->createTagFormFactory($form),
        );

        $response = $controller->edit($tag, $request);

        self::assertSame('/generated/admin_blog_tags_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.tag_updated'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function deleteRemovesUnusedTag(): void
    {
        $request = Request::create('/admin/blog/tags/6/delete', 'POST');
        $tag     = $this->createTag(6, 'unused');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($tag);
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            entityManager: $entityManager,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($form),
        );

        $response = $controller->delete($tag, $request);

        self::assertSame('/generated/admin_blog_tags_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.tag_deleted'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function deleteKeepsTagWhenItIsInUse(): void
    {
        $request = Request::create('/admin/blog/tags/6/delete', 'POST');
        $tag     = $this->createTag(6, 'used');
        $tag->getArticles()->add((new BlogArticle())->setSlug('article'));

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
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

        $response = $controller->delete($tag, $request);

        self::assertSame('/generated/admin_blog_tags_index', $response->headers->get('Location'));
        self::assertSame(['admin.flash.tag_in_use'], $request->getSession()->getFlashBag()->get('error'));
    }

    #[Test]
    public function deleteRejectsInvalidCsrfForm(): void
    {
        $request = Request::create('/admin/blog/tags/6/delete', 'POST');
        $tag     = $this->createTag(6, 'used');
        $form    = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $controller = $this->createController(
            request: $request,
            csrfOnlyFormFactory: $this->createCsrfOnlyFormFactory($form),
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $controller->delete($tag, $request);
    }

    private function createController(
        ?Request $request = null,
        ?BlogTagRepository $repository = null,
        ?EntityManagerInterface $entityManager = null,
        ?CsrfOnlyFormFactory $csrfOnlyFormFactory = null,
        ?GetFilterFormFactory $filterFormFactory = null,
        ?FormFactoryInterface $formFactory = null,
        ?Environment $twig = null,
    ): BlogTagController {
        $request ??= Request::create('/admin/blog/tags', 'GET');

        $controller = new BlogTagController(
            $repository ?? $this->createMock(BlogTagRepository::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $csrfOnlyFormFactory ?? ControllerTestHelper::csrfOnlyFormFactory(),
            $filterFormFactory ?? ControllerTestHelper::filterFormFactory(),
            LocaleTestSupport::create(),
        );

        ControllerTestHelper::bind($controller, $request, array_filter([
            'twig'         => $twig,
            'form.factory' => $formFactory,
        ]));

        return $controller;
    }

    private function createTagFormFactory(FormInterface $form): FormFactoryInterface
    {
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        return $formFactory;
    }

    private function createCsrfOnlyFormFactory(FormInterface $form): CsrfOnlyFormFactory
    {
        $factory = $this->createMock(CsrfOnlyFormFactory::class);
        $factory->method('createNamed')->willReturn($form);

        return $factory;
    }

    private function createTag(int $id, string $slug): BlogTag
    {
        $tag = (new BlogTag())
            ->setSlug($slug)
            ->ensureTranslations();

        $translation = $tag->getTranslation('es');
        if ($translation !== null) {
            $translation->setName(strtoupper($slug));
        }

        $reflection = new ReflectionProperty(BlogTag::class, 'id');
        $reflection->setValue($tag, $id);

        return $tag;
    }
}
