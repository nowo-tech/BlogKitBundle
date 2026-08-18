<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use PHPUnit\Framework\Attributes\Test;

final class BlogCommentRepositoryTest extends DoctrineTestCase
{
    private BlogCommentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BlogCommentRepository($this->registry);
    }

    #[Test]
    public function repositoryMethodsReturnEmptyCollectionsWhenNoCommentsExist(): void
    {
        $article = $this->createArticle(
            slug: 'empty-comments',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        self::assertSame([], $this->repository->findApprovedRootCommentsByArticle($article));
        self::assertSame([], $this->repository->findForModeration());
        self::assertSame([], $this->repository->findFiltered([]));
        self::assertSame(0, $this->repository->countPending());
    }

    #[Test]
    public function findApprovedRootCommentsByArticleReturnsApprovedRootsWithApprovedRepliesOnly(): void
    {
        $staff   = $this->createUser('staff@example.test');
        $article = $this->createArticle(
            slug: 'comments-article',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );
        $otherArticle = $this->createArticle(
            slug: 'other-article',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-02-02T00:00:00+00:00'),
        );

        $firstRoot = $this->createComment(
            article: $article,
            authorName: 'Alice',
            body: 'First root',
            status: BlogCommentStatus::Approved,
            createdAt: new DateTimeImmutable('2026-02-10T10:00:00+00:00'),
        );
        $secondRoot = $this->createComment(
            article: $article,
            authorName: 'Bob',
            body: 'Second root',
            status: BlogCommentStatus::Approved,
            createdAt: new DateTimeImmutable('2026-02-11T10:00:00+00:00'),
        );
        $this->createComment(
            article: $article,
            authorName: 'Pending root',
            body: 'Should stay hidden',
            status: BlogCommentStatus::Pending,
            createdAt: new DateTimeImmutable('2026-02-12T10:00:00+00:00'),
        );
        $approvedReply = $this->createComment(
            article: $article,
            authorName: 'Staff',
            body: 'Approved reply',
            status: BlogCommentStatus::Approved,
            parent: $firstRoot,
            staffAuthor: $staff,
            createdAt: new DateTimeImmutable('2026-02-10T11:00:00+00:00'),
        );
        $this->createComment(
            article: $article,
            authorName: 'Pending reply',
            body: 'Should stay hidden',
            status: BlogCommentStatus::Pending,
            parent: $firstRoot,
            createdAt: new DateTimeImmutable('2026-02-10T12:00:00+00:00'),
        );
        $this->createComment(
            article: $otherArticle,
            authorName: 'Other article root',
            body: 'Ignored',
            status: BlogCommentStatus::Approved,
            createdAt: new DateTimeImmutable('2026-02-13T10:00:00+00:00'),
        );

        $items = $this->repository->findApprovedRootCommentsByArticle($article);

        self::assertSame(
            [$firstRoot->getId(), $secondRoot->getId()],
            array_map(static fn (BlogComment $comment): ?int => $comment->getId(), $items),
        );
        $this->entityManager->clear();
        $reloaded = $this->repository->find($firstRoot->getId());
        self::assertInstanceOf(BlogComment::class, $reloaded);
        self::assertCount(1, $reloaded->getApprovedReplies());
        self::assertSame($approvedReply->getId(), $reloaded->getApprovedReplies()[0]->getId());
        self::assertSame('staff@example.test', $reloaded->getApprovedReplies()[0]->getStaffAuthor()?->getUserIdentifier());
    }

    #[Test]
    public function findForModerationCanReturnAllStatusesOrOneSpecificStatus(): void
    {
        $article = $this->createArticle(
            slug: 'moderation-article',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
        );

        $approved = $this->createComment(
            article: $article,
            authorName: 'Approved',
            body: 'Approved body',
            status: BlogCommentStatus::Approved,
            createdAt: new DateTimeImmutable('2026-03-01T10:00:00+00:00'),
        );
        $pending = $this->createComment(
            article: $article,
            authorName: 'Pending',
            body: 'Pending body',
            status: BlogCommentStatus::Pending,
            createdAt: new DateTimeImmutable('2026-03-02T10:00:00+00:00'),
        );
        $rejected = $this->createComment(
            article: $article,
            authorName: 'Rejected',
            body: 'Rejected body',
            status: BlogCommentStatus::Rejected,
            createdAt: new DateTimeImmutable('2026-03-03T10:00:00+00:00'),
        );

        $all         = $this->repository->findForModeration();
        $pendingOnly = $this->repository->findForModeration(BlogCommentStatus::Pending);

        self::assertSame(
            [$rejected->getId(), $pending->getId(), $approved->getId()],
            array_map(static fn (BlogComment $comment): ?int => $comment->getId(), $all),
        );
        self::assertSame([$pending->getId()], array_map(static fn (BlogComment $comment): ?int => $comment->getId(), $pendingOnly));
    }

    #[Test]
    public function findFilteredAppliesAuthorArticleAndBodyFilters(): void
    {
        $article = $this->createArticle(
            slug: 'filter-target',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        );
        $otherArticle = $this->createArticle(
            slug: 'other-target',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-04-02T00:00:00+00:00'),
        );

        $matching = $this->createComment(
            article: $article,
            authorName: 'Jane Doe',
            body: 'Helpful moderation body',
            status: BlogCommentStatus::Pending,
            authorEmail: 'jane@example.test',
            createdAt: new DateTimeImmutable('2026-04-03T10:00:00+00:00'),
        );
        $sameArticle = $this->createComment(
            article: $article,
            authorName: 'Other person',
            body: 'Helpful moderation body',
            status: BlogCommentStatus::Pending,
            authorEmail: 'other@example.test',
            createdAt: new DateTimeImmutable('2026-04-02T10:00:00+00:00'),
        );
        $sameAuthor = $this->createComment(
            article: $otherArticle,
            authorName: 'Jane Elsewhere',
            body: 'Different body',
            status: BlogCommentStatus::Pending,
            authorEmail: 'jane@example.test',
            createdAt: new DateTimeImmutable('2026-04-01T10:00:00+00:00'),
        );

        $byAuthor  = $this->repository->findFiltered(['author' => 'jane@example.test']);
        $byArticle = $this->repository->findFiltered(['article' => 'filter-target']);
        $byBody    = $this->repository->findFiltered(['body' => 'moderation body']);
        $combined  = $this->repository->findFiltered([
            'author'  => 'jane',
            'article' => 'filter-target',
            'body'    => 'helpful',
        ]);

        self::assertSame(
            [$matching->getId(), $sameAuthor->getId()],
            array_map(static fn (BlogComment $comment): ?int => $comment->getId(), $byAuthor),
        );
        self::assertSame(
            [$matching->getId(), $sameArticle->getId()],
            array_map(static fn (BlogComment $comment): ?int => $comment->getId(), $byArticle),
        );
        self::assertSame(
            [$matching->getId(), $sameArticle->getId()],
            array_map(static fn (BlogComment $comment): ?int => $comment->getId(), $byBody),
        );
        self::assertSame([$matching->getId()], array_map(static fn (BlogComment $comment): ?int => $comment->getId(), $combined));
    }

    #[Test]
    public function countPendingCountsOnlyPendingComments(): void
    {
        $article = $this->createArticle(
            slug: 'pending-count',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
        );

        $this->createComment($article, 'Pending one', 'Body', BlogCommentStatus::Pending);
        $this->createComment($article, 'Pending two', 'Body', BlogCommentStatus::Pending);
        $this->createComment($article, 'Approved', 'Body', BlogCommentStatus::Approved);

        self::assertSame(2, $this->repository->countPending());
    }
}
