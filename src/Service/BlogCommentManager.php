<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates and moderates blog comments.
 *
 * Moderation timestamps come from the injected PSR-20 clock (REQ-DI-001). Moderation actions are
 * logged with the comment id only — never the author name, e-mail, body or IP (REQ-OBS-001).
 */
final readonly class BlogCommentManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BlogCommentRepository $blogCommentRepository,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function createPublicComment(
        BlogArticle $blogArticle,
        string $authorName,
        string $authorEmail,
        string $body,
        Request $request,
    ): BlogComment {
        $blogComment = new BlogComment()
            ->setArticle($blogArticle)
            ->setAuthorName(trim($authorName))
            ->setAuthorEmail(trim($authorEmail))
            ->setBody(trim($body))
            ->setStatus(BlogCommentStatus::Pending)
            ->setIpHash($this->hashIp($request));

        $this->entityManager->persist($blogComment);
        $this->entityManager->flush();

        return $blogComment;
    }

    public function createStaffReply(
        BlogComment $blogComment,
        BlogUserInterface $user,
        string $body,
    ): BlogComment {
        $blogArticle = $blogComment->getArticle();

        $reply = new BlogComment()
            ->setArticle($blogArticle)
            ->setParent($blogComment)
            ->setAuthorName($this->staffDisplayName($user))
            ->setStaffAuthor($user)
            ->setBody(trim($body))
            ->setStatus(BlogCommentStatus::Approved)
            ->setModeratedAt($this->clock->now())
            ->setModeratedBy($user);

        $this->entityManager->persist($reply);
        $this->entityManager->flush();

        return $reply;
    }

    public function approve(BlogComment $blogComment, BlogUserInterface $user): void
    {
        $blogComment
            ->setStatus(BlogCommentStatus::Approved)
            ->setModeratedAt($this->clock->now())
            ->setModeratedBy($user);

        $this->entityManager->flush();
        $this->logModeration('comment_approved', $blogComment);
    }

    public function reject(BlogComment $blogComment, BlogUserInterface $user): void
    {
        $blogComment
            ->setStatus(BlogCommentStatus::Rejected)
            ->setModeratedAt($this->clock->now())
            ->setModeratedBy($user);

        $this->entityManager->flush();
        $this->logModeration('comment_rejected', $blogComment);
    }

    public function delete(BlogComment $blogComment): void
    {
        $commentId = $blogComment->getId();
        $this->entityManager->remove($blogComment);
        $this->entityManager->flush();
        $this->logger->info('Blog comment deleted.', ['bundle' => 'blog_kit', 'action' => 'comment_deleted', 'comment_id' => $commentId]);
    }

    /** @return list<BlogComment> */
    public function approvedCommentsForArticle(BlogArticle $blogArticle): array
    {
        return $this->blogCommentRepository->findApprovedRootCommentsByArticle($blogArticle);
    }

    private function logModeration(string $action, BlogComment $blogComment): void
    {
        $this->logger->info('Blog comment moderated.', ['bundle' => 'blog_kit', 'action' => $action, 'comment_id' => $blogComment->getId()]);
    }

    private function staffDisplayName(BlogUserInterface $user): string
    {
        $email     = $user->getUserIdentifier();
        $localPart = strstr($email, '@', true);

        if ($localPart !== false && $localPart !== '') {
            return ucfirst(str_replace([
                '.',
                '_',
                '-'], ' ', $localPart));
        }

        return 'Equipo';
    }

    private function hashIp(Request $request): ?string
    {
        $ip = $request->getClientIp();

        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $ip);
    }
}
