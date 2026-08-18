<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Form\StaffBlogCommentReplyType;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\RoutingKitBundle\Attribute\Routable;
use Nowo\RoutingKitBundle\Attribute\RouteParam;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public blog comment submission and staff replies on article pages.
 */
final class PublicCommentController extends AbstractController
{
    public function __construct(
        private readonly BlogArticleRepository $blogArticleRepository,
        private readonly BlogCommentRepository $blogCommentRepository,
        private readonly BlogCommentManager $blogCommentManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/blog/{slug}/comments', name: 'blog_comment_create', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    #[Routable(name: 'blog_comment_create', params: [
        new RouteParam('slug', required: true, requirement: '[a-z0-9-]+'),
    ])]
    public function create(string $slug, Request $request): RedirectResponse
    {
        $blogArticle = $this->findPublishedArticle($slug);
        $form        = $this->createForm(PublicBlogCommentType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'blog.comments.error_invalid');

            return $this->redirectToArticle($slug);
        }

        $this->blogCommentManager->createPublicComment(
            $blogArticle,
            (string) $form->get('authorName')->getData(),
            (string) $form->get('authorEmail')->getData(),
            (string) $form->get('body')->getData(),
            $request,
        );

        $this->addFlash('success', 'blog.comments.success_pending');

        return $this->redirectToArticle($slug);
    }

    #[Route('/blog/comments/{id}/reply', name: 'blog_comment_staff_reply', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[Routable(name: 'blog_comment_staff_reply', params: [
        new RouteParam('id', required: true, requirement: '\d+'),
    ])]
    public function staffReply(int $id, Request $request): RedirectResponse
    {
        $parent = $this->blogCommentRepository->find($id);

        if (!$parent instanceof BlogComment) {
            throw $this->createNotFoundException();
        }

        $blogArticle = $parent->getArticle();

        if (!$blogArticle->isPublished()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(StaffBlogCommentReplyType::class, null, [
            'action' => $this->generateUrl('blog_comment_staff_reply', ['id' => $id]),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'blog.comments.error_reply');

            return $this->redirectToArticle($blogArticle->getSlug());
        }

        /** @var array{body: string} $data */
        $data = $form->getData();
        $user = $this->getUser();

        if (!$user instanceof BlogUserInterface) {
            throw $this->createAccessDeniedException();
        }

        $this->blogCommentManager->createStaffReply($parent, $user, $data['body']);
        $this->addFlash('success', 'admin.flash.reply_published');

        return $this->redirectToArticle($blogArticle->getSlug());
    }

    private function findPublishedArticle(string $slug): BlogArticle
    {
        $article = $this->blogArticleRepository->findOneBy(['slug' => $slug, 'published' => true]);

        if (!$article instanceof BlogArticle) {
            throw $this->createNotFoundException($this->translator->trans('blog.article.not_found'));
        }

        return $article;
    }

    private function redirectToArticle(string $slug): RedirectResponse
    {
        return $this->redirectToRoute('blog_show', ['slug' => $slug]);
    }
}
