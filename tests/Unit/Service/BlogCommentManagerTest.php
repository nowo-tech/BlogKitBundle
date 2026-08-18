<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BlogCommentManagerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $repository;
    private BlogCommentManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository    = $this->createMock(BlogCommentRepository::class);
        $this->manager       = new BlogCommentManager($this->entityManager, $this->repository);
    }

    #[Test]
    public function itCreatesPublicCommentAndHashesIp(): void
    {
        $article = new BlogArticle();
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);

        $this->entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(BlogComment::class));
        $this->entityManager->expects(self::once())->method('flush');

        $comment = $this->manager->createPublicComment($article, ' Ana ', ' a@b.com ', ' Hello ', $request);

        self::assertSame($article, $comment->getArticle());
        self::assertSame('Ana', $comment->getAuthorName());
        self::assertSame('a@b.com', $comment->getAuthorEmail());
        self::assertSame('Hello', $comment->getBody());
        self::assertSame(BlogCommentStatus::Pending, $comment->getStatus());
        self::assertSame(hash('sha256', '203.0.113.10'), $comment->getIpHash());
    }

    #[Test]
    public function itCreatesPublicCommentWithoutIpHashWhenClientIpIsEmpty(): void
    {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '']);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $comment = $this->manager->createPublicComment(new BlogArticle(), 'A', 'a@b.c', 'B', $request);

        self::assertNull($comment->getIpHash());
    }

    #[Test]
    public function itCreatesStaffReplyUsingDisplayNameFromEmail(): void
    {
        $parent = (new BlogComment())->setArticle(new BlogArticle());
        $user   = (new TestUser())->setEmail('hector.franco@example.com');

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $reply = $this->manager->createStaffReply($parent, $user, ' Thanks ');

        self::assertSame($parent, $reply->getParent());
        self::assertSame($user, $reply->getStaffAuthor());
        self::assertSame($user, $reply->getModeratedBy());
        self::assertSame('Hector franco', $reply->getAuthorName());
        self::assertSame(BlogCommentStatus::Approved, $reply->getStatus());
        self::assertSame('Thanks', $reply->getBody());
        self::assertNotNull($reply->getModeratedAt());
    }

    #[Test]
    public function itFallsBackToEquipoWhenStaffEmailHasNoLocalPart(): void
    {
        $parent = (new BlogComment())->setArticle(new BlogArticle());
        $user   = (new TestUser())->setEmail('@only-domain.example');

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $reply = $this->manager->createStaffReply($parent, $user, ' Hi ');

        self::assertSame('Equipo', $reply->getAuthorName());
    }

    #[Test]
    public function itApprovesRejectsAndDeletesComments(): void
    {
        $comment = new BlogComment();
        $user    = (new TestUser())->setEmail('mod@example.com');

        $this->entityManager->expects(self::exactly(3))->method('flush');
        $this->entityManager->expects(self::once())->method('remove')->with($comment);

        $this->manager->approve($comment, $user);
        self::assertSame(BlogCommentStatus::Approved, $comment->getStatus());
        self::assertSame($user, $comment->getModeratedBy());
        self::assertNotNull($comment->getModeratedAt());

        $this->manager->reject($comment, $user);
        self::assertSame(BlogCommentStatus::Rejected, $comment->getStatus());
        self::assertSame($user, $comment->getModeratedBy());

        $this->manager->delete($comment);
    }

    #[Test]
    public function itReturnsApprovedCommentsForArticle(): void
    {
        $article  = new BlogArticle();
        $comments = [(new BlogComment())->setArticle($article)];

        $this->repository->expects(self::once())
            ->method('findApprovedRootCommentsByArticle')
            ->with($article)
            ->willReturn($comments);

        self::assertSame($comments, $this->manager->approvedCommentsForArticle($article));
    }
}
