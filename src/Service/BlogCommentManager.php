<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates and moderates blog comments.
 */
final readonly class BlogCommentManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BlogCommentRepository $blogCommentRepository,
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
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($user);

        $this->entityManager->persist($reply);
        $this->entityManager->flush();

        return $reply;
    }

    public function approve(BlogComment $blogComment, BlogUserInterface $user): void
    {
        $blogComment
            ->setStatus(BlogCommentStatus::Approved)
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($user);

        $this->entityManager->flush();
    }

    public function reject(BlogComment $blogComment, BlogUserInterface $user): void
    {
        $blogComment
            ->setStatus(BlogCommentStatus::Rejected)
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($user);

        $this->entityManager->flush();
    }

    public function delete(BlogComment $blogComment): void
    {
        $this->entityManager->remove($blogComment);
        $this->entityManager->flush();
    }

    /** @return list<BlogComment> */
    public function approvedCommentsForArticle(BlogArticle $blogArticle): array
    {
        return $this->blogCommentRepository->findApprovedRootCommentsByArticle($blogArticle);
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
