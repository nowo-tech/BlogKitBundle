<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Form\BlogSettingsType;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin singleton form for professional blog configuration.
 */
final class BlogSettingsController extends AbstractController
{
    public function __construct(
        private readonly BlogSettingsRepository $blogSettingsRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/blog/settings', name: 'admin_blog_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $blogSettings = $this->blogSettingsRepository->getSingleton();
        $form         = $this->createForm(BlogSettingsType::class, $blogSettings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->blogSettingsRepository->reset();
            $this->addFlash('success', 'admin.flash.settings_saved');

            return $this->redirectToRoute('admin_blog_settings');
        }

        return $this->render('@NowoBlogKitBundle/admin/settings.html.twig', [
            'page_title' => 'admin.settings.title',
            'form'       => $form,
        ]);
    }
}
