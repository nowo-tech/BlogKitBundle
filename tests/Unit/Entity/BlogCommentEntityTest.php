<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogCommentEntityTest extends TestCase
{
    #[Test]
    public function testAccessorsRepliesAndVisibility(): void
    {
        $article = new BlogArticle();
        $parent  = (new BlogComment())
            ->setArticle($article)
            ->setAuthorName('Alice')
            ->setAuthorEmail('alice@example.com')
            ->setBody('Hello')
            ->setStatus(BlogCommentStatus::Approved)
            ->setIpHash('hash');

        $approvedReply = (new BlogComment())
            ->setArticle($article)
            ->setAuthorName('Bob')
            ->setBody('Reply')
            ->setStatus(BlogCommentStatus::Approved);
        $pendingReply = (new BlogComment())
            ->setArticle($article)
            ->setAuthorName('Carol')
            ->setBody('Pending')
            ->setStatus(BlogCommentStatus::Pending);

        $parent->addReply($approvedReply)->addReply($approvedReply)->addReply($pendingReply);
        self::assertSame($parent, $approvedReply->getParent());
        self::assertCount(2, $parent->getReplies());
        self::assertCount(1, $parent->getApprovedReplies());

        $moderator = (new TestUser())->setEmail('mod@example.com');
        $at        = new DateTimeImmutable('2024-06-01');
        $parent->setModeratedAt($at)->setModeratedBy($moderator);

        self::assertNull($parent->getId());
        self::assertSame($article, $parent->getArticle());
        self::assertSame('Alice', $parent->getAuthorName());
        self::assertSame('alice@example.com', $parent->getAuthorEmail());
        self::assertSame('Hello', $parent->getBody());
        self::assertSame(BlogCommentStatus::Approved, $parent->getStatus());
        self::assertTrue($parent->isVisibleOnSite());
        self::assertFalse($pendingReply->isVisibleOnSite());
        self::assertSame($at, $parent->getModeratedAt());
        self::assertSame($moderator, $parent->getModeratedBy());
        self::assertSame('hash', $parent->getIpHash());
        self::assertFalse($parent->isStaffReply());
        self::assertSame('Alice', $parent->displayAuthorName());
    }

    #[Test]
    public function testStaffReplyDisplayName(): void
    {
        $staff   = (new TestUser())->setEmail('hector.franco@example.com');
        $comment = (new BlogComment())
            ->setArticle(new BlogArticle())
            ->setStaffAuthor($staff)
            ->setAuthorName('')
            ->setBody('Staff note');

        self::assertTrue($comment->isStaffReply());
        self::assertSame('Equipo', $comment->displayAuthorName());

        $comment->setAuthorName('Héctor');
        self::assertSame('Héctor', $comment->displayAuthorName());
    }
}
