<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Form\BlogSettingsType;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Ui\BlogKitAreaNav;
use Nowo\BlogKitBundle\Ui\BlogKitPageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin singleton form for professional blog configuration (one path per section).
 */
final class BlogSettingsController extends AbstractController
{
    public function __construct(
        private readonly BlogSettingsRepository $blogSettingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BlogKitPageFactory $pageFactory,
    ) {
    }

    #[Route('/admin/blog/settings', name: 'admin_blog_settings', methods: ['GET'])]
    public function settings(): RedirectResponse
    {
        return $this->redirectToRoute('admin_blog_settings_listing');
    }

    #[Route('/admin/blog/settings/listing', name: 'admin_blog_settings_listing', methods: ['GET', 'POST'])]
    public function listing(Request $request): Response
    {
        return $this->handleSection($request, BlogSettingsType::SECTION_LISTING);
    }

    #[Route('/admin/blog/settings/cards', name: 'admin_blog_settings_cards', methods: ['GET', 'POST'])]
    public function cards(Request $request): Response
    {
        return $this->handleSection($request, BlogSettingsType::SECTION_CARDS);
    }

    #[Route('/admin/blog/settings/index-aside', name: 'admin_blog_settings_index_aside', methods: ['GET', 'POST'])]
    public function indexAside(Request $request): Response
    {
        return $this->handleSection($request, BlogSettingsType::SECTION_INDEX_ASIDE);
    }

    #[Route('/admin/blog/settings/article', name: 'admin_blog_settings_article', methods: ['GET', 'POST'])]
    public function article(Request $request): Response
    {
        return $this->handleSection($request, BlogSettingsType::SECTION_ARTICLE);
    }

    #[Route('/admin/blog/settings/comments', name: 'admin_blog_settings_comments', methods: ['GET', 'POST'])]
    public function comments(Request $request): Response
    {
        return $this->handleSection($request, BlogSettingsType::SECTION_COMMENTS);
    }

    private function handleSection(Request $request, string $section): Response
    {
        $blogSettings = $this->blogSettingsRepository->getSingleton();
        $route        = BlogKitAreaNav::SETTINGS_SECTIONS[$section];
        $form         = $this->createForm(BlogSettingsType::class, $blogSettings, [
            BlogSettingsType::OPTION_SECTION => $section,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->blogSettingsRepository->reset();
            $this->addFlash('success', 'admin.flash.settings_saved');

            return $this->redirectToRoute($route);
        }

        $view = $form->createView();

        return $this->render($this->pageFactory->template('settings', '@NowoBlogKitBundle/admin/settings.html.twig'), $this->pageFactory->context([
            'page'         => $this->pageFactory->settings($section, $view),
            'page_title'   => 'admin.settings.title',
            'form'         => $view,
            'edit_section' => $section,
        ]));
    }
}
