<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Model\BlogStaffDisplayNameInterface;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Tests\Support\RecordingLogger;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

use const JSON_THROW_ON_ERROR;

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
    public function itUsesTheInjectedClockAndLogsModerationWithoutPersonalData(): void
    {
        $clock   = new MockClock('2026-10-20 12:00:00');
        $logger  = new RecordingLogger();
        $manager = new BlogCommentManager($this->entityManager, $this->repository, $clock, $logger);
        $user    = new TestUser()->setEmail('mod@example.com');
        $comment = new BlogComment()->setArticle(new BlogArticle())->setAuthorEmail('secret@example.com');

        $manager->approve($comment, $user);
        self::assertEquals($clock->now(), $comment->getModeratedAt());

        $clock->modify('+1 hour');
        $manager->reject($comment, $user);
        self::assertEquals($clock->now(), $comment->getModeratedAt());

        $reply = $manager->createStaffReply($comment, $user, 'Hi');
        self::assertEquals($clock->now(), $reply->getModeratedAt());

        $manager->delete($comment);

        self::assertSame(['comment_approved', 'comment_rejected', 'comment_deleted'], array_map(
            static fn (array $record): mixed => $record['context']['action'],
            $logger->records,
        ));
        self::assertStringNotContainsString('secret@example.com', json_encode($logger->records, JSON_THROW_ON_ERROR));
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
    public function itSignsStaffRepliesWithTheConfiguredNameNotTheLoginIdentifier(): void
    {
        $parent = new BlogComment()->setArticle(new BlogArticle());
        $user   = new TestUser()->setEmail('hector.franco@example.com');

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $reply = $this->manager->createStaffReply($parent, $user, ' Thanks ');

        self::assertSame($parent, $reply->getParent());
        self::assertSame($user, $reply->getStaffAuthor());
        self::assertSame($user, $reply->getModeratedBy());
        // SEC-004 I5: the e-mail local part used to be published as the author name.
        self::assertSame('Team', $reply->getAuthorName());
        self::assertSame(BlogCommentStatus::Approved, $reply->getStatus());
        self::assertSame('Thanks', $reply->getBody());
        self::assertNotNull($reply->getModeratedAt());
    }

    #[Test]
    public function itUsesTheStaffDisplayNameOfTheUserWhenProvided(): void
    {
        $parent = new BlogComment()->setArticle(new BlogArticle());
        $named  = new class implements BlogUserInterface, BlogStaffDisplayNameInterface {
            public string $displayName = 'Editorial desk';

            public function getId(): int
            {
                return 7;
            }

            public function getUserIdentifier(): string
            {
                return 'editor@example.com';
            }

            public function getBlogDisplayName(): string
            {
                return $this->displayName;
            }
        };

        $manager = new BlogCommentManager($this->entityManager, $this->createStub(BlogCommentRepository::class), staffName: 'Redacción');

        self::assertSame('Editorial desk', $manager->createStaffReply($parent, $named, 'Hi')->getAuthorName());
        $named->displayName = '  ';
        self::assertSame('Redacción', $manager->createStaffReply($parent, $named, 'Hi')->getAuthorName());
    }

    #[Test]
    public function itApprovesRejectsAndDeletesComments(): void
    {
        $comment = new BlogComment();
        $user    = new TestUser()->setEmail('mod@example.com');

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
        $comments = [new BlogComment()->setArticle($article)];

        $this->repository->expects(self::once())
            ->method('findApprovedRootCommentsByArticle')
            ->with($article)
            ->willReturn($comments);

        self::assertSame($comments, $this->manager->approvedCommentsForArticle($article));
    }
}
