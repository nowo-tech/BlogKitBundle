<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Controller\RequiresValidFormTrait;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Form\BlogArticleFilterType;
use Nowo\BlogKitBundle\Form\BlogArticleType;
use Nowo\BlogKitBundle\Form\BlogInlineModalType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessDenied;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD for blog articles.
 */
final class BlogArticleController extends AbstractController
{
    use AdminListFilterTrait;
    use RequiresValidFormTrait;

    public function __construct(
        private readonly BlogArticleRepository $blogArticleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfOnlyFormFactory $csrfOnlyFormFactory,
        private readonly GetFilterFormFactory $filterFormFactory,
        private readonly BlogLocales $blogLocales,
        private readonly BlogKitAccessDenied $accessDenied,
        #[Autowire('%nowo_blog_kit.web_ui.page_size%')]
        private readonly int $pageSize,
    ) {
    }

    #[Route('/admin/blog', name: 'admin_blog_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filterForm = $this->filterFormFactory->create(BlogArticleFilterType::class, [], [
            'action' => $this->generateUrl('admin_blog_index'),
        ]);
        $filters = $this->resolveAdminListFilters($request, $filterForm, [
            'title',
            'slug',
            'published',
        ]);

        $page   = max(1, $request->query->getInt('page', 1));
        $result = $this->blogArticleRepository->findFilteredPaginated(
            $filters,
            $page,
            $this->pageSize,
            $this->accessDenied->resourceAccess()->articleListingCreatedById(),
        );

        /** @var array<int, FormView> $deleteForms */
        $deleteForms = [];
        foreach ($result['items'] as $item) {
            $id = $item->getId();
            if ($id === null || !$this->accessDenied->resourceAccess()->canManageArticle($item)) {
                continue;
            }
            $deleteForms[$id] = $this->csrfOnlyFormFactory->createNamed(
                $this->generateUrl('admin_blog_delete', ['id' => $id]),
                'delete' . $id,
            )->createView();
        }

        return $this->render('@NowoBlogKitBundle/admin/index.html.twig', [
            'page_title'   => 'admin.articles.title',
            'items'        => $result['items'],
            'pagination'   => $result,
            'filters'      => $filters,
            'filter_form'  => $filterForm->createView(),
            'delete_forms' => $deleteForms,
        ]);
    }

    #[Route('/admin/blog/new', name: 'admin_blog_new')]
    public function new(Request $request): Response
    {
        $blogArticle = new BlogArticle();
        $blogArticle->ensureTranslations($this->blogLocales->getAll());

        $form = $this->createForm(BlogArticleType::class, $blogArticle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            if ($user instanceof BlogUserInterface) {
                $blogArticle->setCreatedBy($user);
            }

            $this->entityManager->persist($blogArticle);
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.flash.article_created');

            return $this->redirectToRoute('admin_blog_index');
        }

        return $this->render('@NowoBlogKitBundle/admin/form.html.twig', [
            'page_title' => 'admin.articles.new',
            'form'       => $form,
        ]);
    }

    #[Route('/admin/blog/{id}/edit', name: 'admin_blog_edit', requirements: ['id' => '\d+'])]
    public function edit(BlogArticle $blogArticle, Request $request): Response
    {
        $this->accessDenied->denyUnlessCanManageArticle($blogArticle);

        $form = $this->createForm(BlogArticleType::class, $blogArticle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.flash.article_updated');

            return $this->redirectToRoute('admin_blog_index');
        }

        return $this->render('@NowoBlogKitBundle/admin/form.html.twig', [
            'page_title' => 'admin.articles.edit',
            'form'       => $form,
            'article'    => $blogArticle,
        ]);
    }

    #[Route('/admin/blog/{id}/edit-modal', name: 'admin_blog_edit_modal', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editModal(BlogArticle $blogArticle, Request $request): Response
    {
        $this->accessDenied->denyUnlessCanManageArticle($blogArticle);

        return $this->renderInlineModal($blogArticle, $request);
    }

    #[Route('/admin/blog/{id}/inline', name: 'admin_blog_inline_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function inlineUpdate(BlogArticle $blogArticle, Request $request): Response
    {
        $this->accessDenied->denyUnlessCanManageArticle($blogArticle);

        return $this->handleInlineSubmit($blogArticle, $request, $this->entityManager);
    }

    #[Route('/admin/blog/{id}/delete', name: 'admin_blog_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(BlogArticle $blogArticle, Request $request): RedirectResponse
    {
        $this->accessDenied->denyUnlessCanManageArticle($blogArticle);

        $form = $this->csrfOnlyFormFactory->createNamed(
            $this->generateUrl('admin_blog_delete', ['id' => $blogArticle->getId()]),
            'delete' . $blogArticle->getId(),
        );
        $form->handleRequest($request);
        $this->requireValidCsrfForm($form);

        $this->entityManager->remove($blogArticle);
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.flash.article_deleted');

        return $this->redirectToRoute('admin_blog_index');
    }

    private function renderInlineModal(BlogArticle $blogArticle, Request $request): Response
    {
        $locale = $request->getLocale();
        $form   = $this->createInlineForm($blogArticle);

        return $this->render('@NowoBlogKitBundle/admin/_modal_form.html.twig', [
            'form'   => $form,
            'locale' => $locale,
        ]);
    }

    private function handleInlineSubmit(
        BlogArticle $blogArticle,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $locale = $request->getLocale();
        $form   = $this->createInlineForm($blogArticle);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('@NowoBlogKitBundle/admin/_modal_form.html.twig', [
                'form'   => $form,
                'locale' => $locale,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $entityManager->flush();
        $this->addFlash('success', 'admin.flash.article_updated');

        $referer = $request->headers->get('Referer');

        return $this->redirect($referer ?: $this->generateUrl('blog_index'));
    }

    /** @return FormInterface<mixed> */
    private function createInlineForm(BlogArticle $blogArticle): FormInterface
    {
        $blogArticle->ensureTranslations($this->blogLocales->getAll());

        return $this->createForm(BlogInlineModalType::class, $blogArticle, [
            'action' => $this->generateUrl('admin_blog_inline_update', ['id' => $blogArticle->getId()]),
            'method' => 'POST',
        ]);
    }
}
