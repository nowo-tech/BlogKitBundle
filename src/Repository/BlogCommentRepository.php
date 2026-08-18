<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;

/**
 * @extends ServiceEntityRepository<BlogComment>
 */
final class BlogCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogComment::class);
    }

    /** @return list<BlogComment> */
    public function findApprovedRootCommentsByArticle(BlogArticle $blogArticle): array
    {
        return $this->createQueryBuilder('comment')
            ->leftJoin('comment.replies', 'reply', 'WITH', 'reply.blogCommentStatus = :status')
            ->addSelect('reply')
            ->leftJoin('comment.staffAuthor', 'staff')
            ->addSelect('staff')
            ->leftJoin('reply.staffAuthor', 'replyStaff')
            ->addSelect('replyStaff')
            ->andWhere('comment.blogArticle = :article')
            ->andWhere('comment.blogComment IS NULL')
            ->andWhere('comment.blogCommentStatus = :status')
            ->setParameter('article', $blogArticle)
            ->setParameter('status', BlogCommentStatus::Approved)
            ->orderBy('comment.createdAt', 'ASC')
            ->addOrderBy('reply.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<BlogComment> */
    public function findForModeration(?BlogCommentStatus $blogCommentStatus = null): array
    {
        return $this->findFiltered([], $blogCommentStatus);
    }

    /**
     * @param array<string, string> $filters Keys: author, article, body
     *
     * @return list<BlogComment>
     */
    public function findFiltered(array $filters, ?BlogCommentStatus $blogCommentStatus = null): array
    {
        $queryBuilder = $this->createQueryBuilder('comment')
            ->leftJoin('comment.blogArticle', 'article')
            ->addSelect('article')
            ->leftJoin('comment.blogComment', 'parent')
            ->addSelect('parent')
            ->leftJoin('comment.staffAuthor', 'staff')
            ->addSelect('staff')
            ->orderBy('comment.createdAt', 'DESC');

        if ($blogCommentStatus instanceof BlogCommentStatus) {
            $queryBuilder
                ->andWhere('comment.blogCommentStatus = :status')
                ->setParameter('status', $blogCommentStatus);
        }

        if (($filters['author'] ?? '') !== '') {
            $queryBuilder
                ->andWhere("LOWER(comment.authorName) LIKE :author OR LOWER(COALESCE(comment.authorEmail, '')) LIKE :author")
                ->setParameter('author', '%' . mb_strtolower($filters['author']) . '%');
        }

        if (($filters['article'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(article.slug) LIKE :article')
                ->setParameter('article', '%' . mb_strtolower($filters['article']) . '%');
        }

        if (($filters['body'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(comment.body) LIKE :body')
                ->setParameter('body', '%' . mb_strtolower($filters['body']) . '%');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('comment')
            ->select('COUNT(comment.id)')
            ->andWhere('comment.blogCommentStatus = :status')
            ->setParameter('status', BlogCommentStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
