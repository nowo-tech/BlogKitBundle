<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller\Admin;

use Nowo\BlogKitBundle\Controller\RequiresValidFormTrait;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Form\BlogCommentFilterType;
use Nowo\BlogKitBundle\Form\StaffBlogCommentReplyType;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;

/**
 * Moderation queue for blog comments (approve, reject, reply, delete).
 */
final class BlogCommentController extends AbstractController
{
    use AdminListFilterTrait;
    use RequiresValidFormTrait;

    private const array FILTER_KEYS = [
        'author',
        'article',
        'body'];

    public function __construct(
        private readonly BlogCommentRepository $blogCommentRepository,
        private readonly BlogCommentManager $blogCommentManager,
        private readonly CsrfOnlyFormFactory $csrfOnlyFormFactory,
        private readonly GetFilterFormFactory $filterFormFactory,
    ) {
    }

    #[Route('/admin/blog/comments', name: 'admin_blog_comments_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statusFilter = $request->query->getString('status', 'pending');
        if (!in_array($statusFilter, [
            'pending',
            'approved',
            'rejected',
            'all'], true)) {
            $statusFilter = 'pending';
        }

        $filterForm = $this->filterFormFactory->create(BlogCommentFilterType::class, [
            'status' => $statusFilter,
        ], [
            'action' => $this->generateUrl('admin_blog_comments_index'),
        ]);
        $filters = $this->resolveAdminListFilters($request, $filterForm, self::FILTER_KEYS);

        if ($filterForm->isSubmitted() && $filterForm->has('status')) {
            $fromForm = trim((string) ($filterForm->get('status')->getData() ?? ''));
            if ($fromForm !== '' && in_array($fromForm, [
                'pending',
                'approved',
                'rejected',
                'all'], true)) {
                $statusFilter = $fromForm;
            }
        }

        $status = match ($statusFilter) {
            'approved' => BlogCommentStatus::Approved,
            'rejected' => BlogCommentStatus::Rejected,
            'all'      => null,
            default    => BlogCommentStatus::Pending,
        };

        $items = $this->blogCommentRepository->findFiltered($filters, $status);

        $queryParams = array_filter(array_merge(['status' => $statusFilter], $filters), static fn (string $v): bool => $v !== '');

        /** @var array<int, FormView> $replyForms */
        $replyForms = [];
        /** @var array<int, array{approve: FormView, reject: FormView, delete: FormView}> $actionForms */
        $actionForms = [];
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            $replyForms[$id] = $this->createForm(StaffBlogCommentReplyType::class, null, [
                'action' => $this->generateUrl('admin_blog_comment_reply', ['id' => $id] + $queryParams),
                'method' => 'POST',
            ])->createView();
            $actionForms[$id] = [
                'approve' => $this->csrfOnlyFormFactory->createNamed(
                    $this->generateUrl('admin_blog_comment_approve', ['id' => $id] + $queryParams),
                    'approve' . $id,
                )->createView(),
                'reject' => $this->csrfOnlyFormFactory->createNamed(
                    $this->generateUrl('admin_blog_comment_reject', ['id' => $id] + $queryParams),
                    'reject' . $id,
                )->createView(),
                'delete' => $this->csrfOnlyFormFactory->createNamed(
                    $this->generateUrl('admin_blog_comment_delete', ['id' => $id] + $queryParams),
                    'delete' . $id,
                )->createView(),
            ];
        }

        return $this->render('@NowoBlogKitBundle/admin/comments/index.html.twig', [
            'page_title'    => 'admin.comments.title',
            'items'         => $items,
            'filters'       => $filters,
            'filter'        => $statusFilter,
            'filter_form'   => $filterForm->createView(),
            'pending_count' => $this->blogCommentRepository->countPending(),
            'reply_forms'   => $replyForms,
            'action_forms'  => $actionForms,
        ]);
    }

    #[Route('/admin/blog/comments/{id}/approve', name: 'admin_blog_comment_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(BlogComment $blogComment, Request $request): RedirectResponse
    {
        $this->requireCsrfAction($request, 'admin_blog_comment_approve', 'approve' . $blogComment->getId(), $blogComment);
        $user = $this->requireUser();

        $this->blogCommentManager->approve($blogComment, $user);
        $this->addFlash('success', 'admin.flash.comment_approved');

        return $this->redirectToCommentsIndex($request);
    }

    #[Route('/admin/blog/comments/{id}/reject', name: 'admin_blog_comment_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(BlogComment $blogComment, Request $request): RedirectResponse
    {
        $this->requireCsrfAction($request, 'admin_blog_comment_reject', 'reject' . $blogComment->getId(), $blogComment);
        $user = $this->requireUser();

        $this->blogCommentManager->reject($blogComment, $user);
        $this->addFlash('success', 'admin.flash.comment_rejected');

        return $this->redirectToCommentsIndex($request);
    }

    #[Route('/admin/blog/comments/{id}/reply', name: 'admin_blog_comment_reply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reply(BlogComment $blogComment, Request $request): RedirectResponse
    {
        $user = $this->requireUser();

        $form = $this->createForm(StaffBlogCommentReplyType::class, null, [
            'action' => $this->generateUrl('admin_blog_comment_reply', ['id' => $blogComment->getId()]),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'admin.flash.reply_invalid');

            return $this->redirectToCommentsIndex($request);
        }

        /** @var array{body: string} $data */
        $data = $form->getData();
        $this->blogCommentManager->createStaffReply($blogComment, $user, $data['body']);

        if ($blogComment->getStatus() === BlogCommentStatus::Pending) {
            $this->blogCommentManager->approve($blogComment, $user);
        }

        $this->addFlash('success', 'admin.flash.reply_published');

        return $this->redirectToCommentsIndex($request);
    }

    #[Route('/admin/blog/comments/{id}/delete', name: 'admin_blog_comment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(BlogComment $blogComment, Request $request): RedirectResponse
    {
        $this->requireCsrfAction($request, 'admin_blog_comment_delete', 'delete' . $blogComment->getId(), $blogComment);

        $this->blogCommentManager->delete($blogComment);
        $this->addFlash('success', 'admin.flash.comment_deleted');

        return $this->redirectToCommentsIndex($request);
    }

    private function requireCsrfAction(Request $request, string $route, string $tokenId, BlogComment $blogComment): void
    {
        $form = $this->csrfOnlyFormFactory->createNamed(
            $this->generateUrl($route, ['id' => $blogComment->getId()]),
            $tokenId,
        );
        $form->handleRequest($request);
        $this->requireValidCsrfForm($form);
    }

    private function redirectToCommentsIndex(Request $request): RedirectResponse
    {
        $params = [];
        $status = $request->request->getString('_return_status', $request->query->getString('status'));

        if ($status !== '') {
            $params['status'] = $status;
        }

        foreach (self::FILTER_KEYS as $key) {
            $value = $request->request->getString('_return_' . $key, $request->query->getString($key));

            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return $this->redirectToRoute('admin_blog_comments_index', $params);
    }

    private function requireUser(): BlogUserInterface
    {
        $user = $this->getUser();

        if (!$user instanceof BlogUserInterface) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
