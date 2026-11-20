<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Database;

use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitConflictSubscriber;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function hash;

/**
 * 1.11 (REQ-RUNTIME-003 rule 4) on a real server, two connections: the settings table holds one row however many first
 * requests race, and a public comment submitted twice (double click, retry) is stored once.
 */
#[Group('database')]
final class SingletonAndSubmitRaceTest extends DatabaseTestCase
{
    private const string TOKEN = 'formtoken0000000000000000000001';

    #[Test]
    public function aFirstRequestLosingTheInsertReadsTheWinnersRow(): void
    {
        $repository = new BlogSettingsRepository($this->registry);
        // The other first request inserted its row (committed) between this one's read and its insert.
        $this->writer->insert('content_blog_settings', $this->writerId('content_blog_settings') + ['singleton_key' => BlogSettings::SINGLETON_KEY]);

        new ReflectionMethod($repository, 'insertDefaultRow')->invoke($repository);

        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_settings'), 'the unique key refused the second row, quietly');
        self::assertInstanceOf(BlogSettings::class, $repository->getSingleton());
        $this->assertConnectionUsable();
    }

    #[Test]
    public function theLoserInsideAHostTransactionRollsBackToItsSavepointOnly(): void
    {
        $repository = new BlogSettingsRepository($this->registry);
        $this->writer->insert('content_blog_settings', $this->writerId('content_blog_settings') + ['singleton_key' => BlogSettings::SINGLETON_KEY]);

        $this->blog->beginTransaction();
        $this->blog->executeStatement("UPDATE content_blog_settings SET per_page = 7 WHERE singleton_key = 'main'");
        new ReflectionMethod($repository, 'insertDefaultRow')->invoke($repository);
        // On PostgreSQL a failed statement would have aborted the host's transaction: this read proves it did not.
        self::assertSame(7, (int) $this->blog->fetchOne('SELECT per_page FROM content_blog_settings'));
        $this->blog->commit();

        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_settings'));
        $this->assertConnectionUsable();
    }

    #[Test]
    public function anEmptyTableGetsExactlyOneRowFromTheFirstRead(): void
    {
        $repository = new BlogSettingsRepository($this->registry);
        $first      = $repository->getSingleton();
        $repository->reset();

        self::assertSame($first->getId(), $repository->getSingleton()->getId());
        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_settings'));
        self::assertSame(BlogSettings::SINGLETON_KEY, $first->getSingletonKey());
    }

    #[Test]
    public function aCommentSubmittedTwiceIsStoredOnce(): void
    {
        $article = $this->createArticle('post');
        $request = Request::create('/blog/post/comments', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);

        $first  = $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request, self::TOKEN);
        $second = $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request, self::TOKEN);

        self::assertSame($first->getId(), $second->getId(), 'the second submission returns the stored comment');
        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'));

        // The same token on a cached page, another visitor: another key, another comment.
        $other = $this->comments()->createPublicComment($article, 'Bea', 'bea@example.com', 'Hello there', $request, self::TOKEN);
        self::assertNotSame($first->getId(), $other->getId());
        self::assertSame(2, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'));

        // No token (a form rendered before 1.11, host code): no guard, as before.
        $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request);
        $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request, 'not-a-token');
        self::assertSame(4, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'));
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aConcurrentInsertOfTheSameSubmissionIsFoundByTheReplay(): void
    {
        $article = $this->createArticle('post');
        $request = Request::create('/blog/post/comments', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $key     = $this->keyFor($article->getId() ?? 0, 'ana@example.com', 'Hello there');

        $winner = 0;
        $this->raceOnFirstFlush(function () use ($article, $key, &$winner): void {
            $winner = $this->insertCommentFromWriter($article->getId() ?? 0, $key);
        });

        $stored = $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request, self::TOKEN);

        self::assertSame($winner, $stored->getId(), 'the unique key refused the second insert; the replay read the winner');
        self::assertSame(1, $this->registry->resets, 'the closed manager was replaced once');
        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'));
        self::assertTrue($this->entityManager->isOpen());
        $this->assertConnectionUsable();
    }

    /**
     * WB-BlogKit-1: on MySQL / MariaDB (REPEATABLE READ) the host transaction's first read fixed an empty snapshot; the
     * winner committed after it. The refused insert is followed by a locking re-read, which sees the committed row.
     */
    #[Test]
    public function aWinnerCommittedAfterTheHostTransactionStartedIsFound(): void
    {
        $repository = new BlogSettingsRepository($this->registry);
        $this->blog->beginTransaction();
        self::assertSame(0, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_settings'), 'the snapshot is fixed, empty');
        $this->writer->insert('content_blog_settings', $this->writerId('content_blog_settings') + ['singleton_key' => BlogSettings::SINGLETON_KEY]);

        $settings = $repository->getSingleton();

        self::assertNotNull($settings->getId());
        $this->blog->commit();
        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_settings'));
        $this->assertConnectionUsable();
    }

    /**
     * WB-BlogKit-2: the same snapshot problem for the comment replay: the winner of the submission committed after the
     * host transaction's first read. The lookup is a locking read, so the stored comment is returned, nothing is
     * persisted with a detached article.
     */
    #[Test]
    public function aSubmissionStoredAfterTheHostTransactionStartedIsFoundByTheLockingLookup(): void
    {
        $article = $this->createArticle('post');
        $request = Request::create('/blog/post/comments', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $key     = $this->keyFor($article->getId() ?? 0, 'ana@example.com', 'Hello there');

        $this->blog->beginTransaction();
        self::assertSame(0, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'), 'the snapshot is fixed, empty');
        $winner = $this->insertCommentFromWriter($article->getId() ?? 0, $key);

        $stored = $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request, self::TOKEN);

        self::assertSame($winner, $stored->getId());
        $this->blog->commit();
        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'));
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aDoubleSubmitWhileTheFirstIsInFlightAnswers409ThenFindsTheStoredComment(): void
    {
        $article = $this->createArticle('post');
        $request = Request::create('/blog/post/comments', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $key     = $this->keyFor($article->getId() ?? 0, 'ana@example.com', 'Hello there');

        // The first submission has inserted and not committed yet: the second waits for its key, then fails fast.
        $this->writer->beginTransaction();
        $winner = $this->insertCommentFromWriter($article->getId() ?? 0, $key);

        $failure = $this->catchFailure(fn (): BlogComment => $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request, self::TOKEN));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $this->commentRequest(), HttpKernelInterface::MAIN_REQUEST, $failure);
        new BlogKitConflictSubscriber()->onKernelException($event);
        self::assertSame(409, $event->getResponse()?->getStatusCode());
        self::assertSame('CONFLICT_001', $event->getResponse()->headers->get('X-Conflict-Code'));

        $this->writer->commit();
        $stored = $this->comments()->createPublicComment($article, 'Ana', 'ana@example.com', 'Hello there', $request, self::TOKEN);
        self::assertSame($winner, $stored->getId());
        self::assertSame(1, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'));
        $this->assertConnectionUsable();
    }

    private function commentRequest(): Request
    {
        $request = Request::create('/blog/post/comments', 'POST');
        $request->attributes->set('_controller', 'Nowo\\BlogKitBundle\\Controller\\PublicCommentController::create');

        return $request;
    }

    private function keyFor(int $articleId, string $email, string $body): string
    {
        return hash('sha256', self::TOKEN . "\0" . $articleId . "\0" . $email . "\0" . $body);
    }

    private function insertCommentFromWriter(int $articleId, string $key): int
    {
        $this->writer->insert('content_blog_comment', $this->writerId('content_blog_comment') + [
            'article_id'   => $articleId,
            'author_name'  => 'Ana',
            'author_email' => 'ana@example.com',
            'body'         => 'Hello there',
            'status'       => 'pending',
            'created_at'   => '2026-11-20 10:00:00',
            'submit_key'   => $key,
        ]);

        return (int) $this->writer->fetchOne('SELECT id FROM content_blog_comment WHERE submit_key = ?', [$key]);
    }

    private function raceOnFirstFlush(callable $race): void
    {
        $listener = new class($race) {
            private bool $done = false;

            /** @param callable(): mixed $race */
            public function __construct(private $race)
            {
            }

            public function onFlush(): void
            {
                if (!$this->done) {
                    $this->done = true;
                    ($this->race)();
                }
            }
        };
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], $listener);
    }

    private function comments(): BlogCommentManager
    {
        return new BlogCommentManager($this->entityManager, new BlogCommentRepository($this->registry), transactionRunner: $this->runner);
    }

    private function catchFailure(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }
}
