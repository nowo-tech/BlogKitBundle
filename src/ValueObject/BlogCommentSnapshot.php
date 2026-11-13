<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogComment;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 1): what the host ports see of a comment. The author's e-mail and IP hash are left
 * out (personal data a policy does not need).
 */
final readonly class BlogCommentSnapshot
{
    public function __construct(
        public ?int $id,
        public ?int $articleId,
        public string $articleSlug,
        public ?int $parentId,
        public string $status,
        public bool $staffReply,
        public ?string $staffAuthorId,
        public ?string $moderatedById,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function of(BlogComment $comment): self
    {
        $article = $comment->getArticle();

        return new self(
            $comment->getId(),
            $article->getId(),
            $article->getSlug(),
            $comment->getParent()?->getId(),
            $comment->getStatus()->value,
            $comment->isStaffReply(),
            BlogUserRef::idOf($comment->getStaffAuthor()),
            BlogUserRef::idOf($comment->getModeratedBy()),
            $comment->getCreatedAt(),
        );
    }
}
