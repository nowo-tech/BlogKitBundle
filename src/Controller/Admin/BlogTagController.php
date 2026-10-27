<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Controller\RequiresValidFormTrait;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Form\BlogTagFilterType;
use Nowo\BlogKitBundle\Form\BlogTagType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessDenied;
use Nowo\BlogKitBundle\Ui\BlogKitPageFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD for blog tags.
 */
final class BlogTagController extends AbstractController
{
    use AdminListFilterTrait;
    use RequiresValidFormTrait;

    public function __construct(
        private readonly BlogTagRepository $blogTagRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfOnlyFormFactory $csrfOnlyFormFactory,
        private readonly GetFilterFormFactory $filterFormFactory,
        private readonly BlogLocales $blogLocales,
        private readonly BlogKitAccessDenied $accessDenied,
        private readonly BlogKitPageFactory $pageFactory,
    ) {
    }

    #[Route('/admin/blog/tags', name: 'admin_blog_tags_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filterForm = $this->filterFormFactory->create(BlogTagFilterType::class, [], [
            'action' => $this->generateUrl('admin_blog_tags_index'),
        ]);
        $filters = $this->resolveAdminListFilters($request, $filterForm, ['slug', 'name']);
        $items   = $this->blogTagRepository->findFiltered($filters);

        /** @var array<int, FormView> $deleteForms */
        $deleteForms = [];
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id === null || !$this->accessDenied->resourceAccess()->canManageTag($item)) {
                continue;
            }
            $deleteForms[$id] = $this->csrfOnlyFormFactory->createNamed(
                $this->generateUrl('admin_blog_tags_delete', ['id' => $id]),
                'delete' . $id,
            )->createView();
        }

        $filterView = $filterForm->createView();

        return $this->render($this->pageFactory->template('tag_index', '@NowoBlogKitBundle/admin/tags/index.html.twig'), $this->pageFactory->context([
            'page'           => $this->pageFactory->tagIndex($items, $filterView, $filters, $deleteForms),
            'page_title'     => 'admin.tags.title',
            'items'          => $items,
            'article_counts' => $this->blogTagRepository->countArticlesByTagId(),
            'filters'        => $filters,
            'filter_form'    => $filterView,
            'delete_forms'   => $deleteForms,
        ]));
    }

    #[Route('/admin/blog/tags/new', name: 'admin_blog_tags_new')]
    public function new(Request $request): Response
    {
        $blogTag = new BlogTag();
        $blogTag->ensureTranslations($this->blogLocales->getAll());

        $form = $this->createForm(BlogTagType::class, $blogTag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($blogTag);
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.flash.tag_created');

            return $this->redirectToRoute('admin_blog_tags_index');
        }

        return $this->renderForm($form, 'admin.tags.new');
    }

    #[Route('/admin/blog/tags/{id}/edit', name: 'admin_blog_tags_edit', requirements: ['id' => '\d+'])]
    public function edit(BlogTag $blogTag, Request $request): Response
    {
        $this->accessDenied->denyUnlessCanManageTag($blogTag);

        $form = $this->createForm(BlogTagType::class, $blogTag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.flash.tag_updated');

            return $this->redirectToRoute('admin_blog_tags_index');
        }

        return $this->renderForm($form, 'admin.tags.edit', ['tag' => $blogTag]);
    }

    #[Route('/admin/blog/tags/{id}/delete', name: 'admin_blog_tags_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(BlogTag $blogTag, Request $request): RedirectResponse
    {
        $this->accessDenied->denyUnlessCanManageTag($blogTag);

        $form = $this->csrfOnlyFormFactory->createNamed(
            $this->generateUrl('admin_blog_tags_delete', ['id' => $blogTag->getId()]),
            'delete' . $blogTag->getId(),
        );
        $form->handleRequest($request);
        $this->requireValidCsrfForm($form);

        if ($blogTag->getArticles()->count() > 0) {
            $this->addFlash('error', 'admin.flash.tag_in_use');

            return $this->redirectToRoute('admin_blog_tags_index');
        }

        $this->entityManager->remove($blogTag);
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.flash.tag_deleted');

        return $this->redirectToRoute('admin_blog_tags_index');
    }

    /**
     * Tag create / edit page (UiKit form scaffold).
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $vars extra 1.x page variables
     */
    private function renderForm(FormInterface $form, string $title, array $vars = []): Response
    {
        $view = $form->createView();

        return $this->render($this->pageFactory->template('tag_form', '@NowoBlogKitBundle/admin/tags/form.html.twig'), $this->pageFactory->context([
            'page'       => $this->pageFactory->tagForm($view, $title),
            'page_title' => $title,
            'form'       => $view,
        ] + $vars));
    }
}
