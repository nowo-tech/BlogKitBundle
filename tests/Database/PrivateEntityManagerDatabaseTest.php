<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Doctrine\BlogKitHostFlushReporter;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitConflictSubscriber;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Service\BlogTagRegistry;
use Nowo\BlogKitBundle\Tests\Integration\Worker\Entity\HostNote;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\Tests\Support\ForwardingLevelListener;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * Since 1.12.0 (REQ-ARCH-001 rule 6, REQ-RUNTIME-003) on a real server: the bundle's services on transaction-doctrine's
 * private entity manager (`doctrine.private_entity_manager: true`), created on "your" manager's connection with its
 * DBAL middleware, next to your pending change (a `HostNote` scheduled for insertion on your manager).
 *
 * - Your pending change stays pending (never flushed, never cleared) after blog writes, conflicts and replays.
 * - Rule 6 holds in the private mode: 409 + Retry-After + CONFLICT_001 when nothing was committed, CONFLICT_002 without
 *   Retry-After after a commit of the same request; a unique violation is replayed, not a conflict.
 * - A rollback of your transaction around a blog write leaves no ghost row in the private manager.
 * - Host mode: the probe reports your pending change before the bundle flushes your manager.
 */
#[Group('database')]
final class PrivateEntityManagerDatabaseTest extends DatabaseTestCase
{
    use CollectsDeprecations;

    private PrivateEntityManager $private;

    private PrivateManagerRegistry $privateRegistry;

    private BlogKitTransactionRunner $privateRunner;

    private ForwardingLevelListener $levels;

    private HostNote $note;

    protected function setUp(): void
    {
        $this->levels = new ForwardingLevelListener();
        parent::setUp();

        $this->private         = new PrivateEntityManager($this->registry, 'default', BlogArticle::class, BlogKitHostFlushReporter::BUNDLE_NAME);
        $this->levels->target  = $this->private;
        $this->privateRegistry = new PrivateManagerRegistry($this->registry, $this->private, BlogKitHostFlushReporter::OWN_NAMESPACES, 'default');
        $this->privateRunner   = new BlogKitTransactionRunner($this->private, $this->privateRegistry, null, $this->publishedEvents, privateSourceManager: 'default');

        $this->note = new HostNote('pending host change');
        $this->entityManager->persist($this->note);
    }

    #[Test]
    public function yourPendingChangeStaysPendingAfterBlogWrites(): void
    {
        $article = $this->privateArticle('launch');
        $comment = $this->comments()->createPublicComment($article, 'Bea', 'bea@example.com', 'Hello', Request::create('/blog/launch/comments', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));
        $this->comments()->approve($comment, new BlogUserRef('mod-1'));

        self::assertSame(BlogCommentStatus::Approved->value, $this->writer->fetchOne('SELECT status FROM content_blog_comment WHERE id = ?', [$comment->getId()]), 'committed');
        $this->assertYourChangeIsPending();
        self::assertFalse($this->entityManager->contains($comment), 'your manager never held a blog row');
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aLockWaitAnswersConflict001AndOnlyThePrivateManagerIsReplaced(): void
    {
        $comment = $this->privateComment($this->privateArticle('post'));
        $id      = (int) $comment->getId();
        $before  = $this->private->live();
        $this->lockCommentRow($id);

        $failure = $this->catchFailure(fn () => $this->comments()->approve($comment, new BlogUserRef('mod-1')));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $response = $this->answer(Request::create('/admin/blog/comments/' . $id . '/approve', 'POST'), $failure);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));

        $this->writer->rollBack();
        self::assertSame(BlogCommentStatus::Pending->value, $this->blog->fetchOne('SELECT status FROM content_blog_comment WHERE id = ?', [$id]), 'nothing half-saved');
        self::assertNotSame($before, $this->private->live(), 'the failed private manager was dropped');
        self::assertTrue($this->entityManager->isOpen());
        self::assertSame(0, $this->registry->resets, 'your manager was never reset');
        $this->assertYourChangeIsPending();

        $fresh = $this->private->find(BlogComment::class, $id);
        self::assertInstanceOf(BlogComment::class, $fresh);
        $this->comments()->approve($fresh, new BlogUserRef('mod-1'));
        self::assertSame(BlogCommentStatus::Approved->value, $this->writer->fetchOne('SELECT status FROM content_blog_comment WHERE id = ?', [$id]), 'the next write works');
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aConflictAfterACommitOfTheSameRequestAnswersConflict002(): void
    {
        $article = $this->privateArticle('thread');
        $comment = $this->privateComment($article);
        $id      = (int) $comment->getId();
        $request = Request::create('/blog/thread/comments', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($this->ledger));

        $this->comments()->createPublicComment($article, 'Bea', 'bea@example.com', 'First', $request);
        $this->lockCommentRow($id);
        $failure = $this->catchFailure(fn () => $this->comments()->reject($comment, new BlogUserRef('mod-1')));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);

        $response = $this->answer($request, $failure);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));

        $this->writer->rollBack();
        self::assertSame(2, (int) $this->blog->fetchOne('SELECT COUNT(*) FROM content_blog_comment'), 'the first unit stays committed');
        $this->assertYourChangeIsPending();
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aUniqueViolationIsReplayedThroughAFreshPrivateManager(): void
    {
        $race = function (): void {
            $this->insertTagFromWriter('php', ['es' => 'PHP (A)', 'en' => 'PHP (A)']);
        };
        $listener = new class($race) {
            private bool $done = false;

            /** @param callable(): void $race */
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
        // The private manager shares your manager's event manager (your listeners run on its flushes too).
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], $listener);

        $registry = new BlogTagRegistry($this->private, new BlogTagRepository($this->privateRegistry), LocaleTestSupport::create(), $this->privateRunner);
        $registry->syncDefinitions(['php' => ['es' => 'PHP', 'en' => 'PHP']]);

        self::assertSame(1, (int) $this->blog->fetchOne("SELECT COUNT(*) FROM content_blog_tag WHERE slug = 'php'"));
        self::assertSame(2, (int) $this->blog->fetchOne("SELECT COUNT(*) FROM content_blog_tag_translation tt JOIN content_blog_tag t ON t.id = tt.translatable_id WHERE t.slug = 'php' AND tt.name = 'PHP'"));
        self::assertSame(0, $this->registry->resets, 'your manager was never reset');
        $this->assertYourChangeIsPending();
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aRollbackOfYourTransactionLeavesNoGhostRowInThePrivateManager(): void
    {
        $article = $this->privateArticle('ghost');
        $this->blog->beginTransaction();
        $comment = $this->comments()->createPublicComment($article, 'Bea', 'bea@example.com', 'Rolled back', Request::create('/blog/ghost/comments', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));
        $id      = (int) $comment->getId();
        $this->blog->rollBack();

        self::assertSame(0, (int) $this->writer->fetchOne('SELECT COUNT(*) FROM content_blog_comment'));
        self::assertNull($this->private->find(BlogComment::class, $id), 'the rolled-back row is not served from memory');
        $this->assertYourChangeIsPending();
        $this->assertConnectionUsable();
    }

    #[Test]
    public function hostModeReportsYourPendingChangeBeforeFlushingYourManager(): void
    {
        $runner = new BlogKitTransactionRunner($this->entityManager, $this->registry, null, $this->publishedEvents, hostFlushReporter: new BlogKitHostFlushReporter(true));
        $this->entityManager->detach($this->note);
        $article = $this->createArticle('host-mode');
        $this->entityManager->persist(new HostNote('pending host change'));
        $article->setPosition(7);

        self::assertSame(
            ['Your pending changes to ' . HostNote::class . ' were flushed by NowoBlogKitBundle; 2.0 no longer does.'],
            self::deprecationsOf(static fn () => $runner->flushPending()),
        );
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT COUNT(*) FROM worker_host_note'), '1.x: your change is written with the blog change');
    }

    protected function extraMiddlewares(): array
    {
        return [new PrivateEntityManagerMiddleware($this->levels, 'blog_kit_')];
    }

    protected function extraEntityPaths(): array
    {
        return [__DIR__ . '/../Integration/Worker/Entity'];
    }

    private function assertYourChangeIsPending(): void
    {
        self::assertTrue($this->entityManager->getUnitOfWork()->isScheduledForInsert($this->note), 'your pending change is still pending');
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT COUNT(*) FROM worker_host_note'), 'and was never written');
    }

    private function privateArticle(string $slug): BlogArticle
    {
        $article = $this->newPrivateArticle($slug);
        $this->privateRunner->run(fn () => $this->private->persist($article));

        return $article;
    }

    private function newPrivateArticle(string $slug): BlogArticle
    {
        $article = new BlogArticle()->setSlug($slug)->setPublished(true)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle($slug)->setBody('Body ' . $slug));

        return $article;
    }

    private function privateComment(BlogArticle $article): BlogComment
    {
        $comment = new BlogComment()->setArticle($article)->setAuthorName('Ana')->setBody('Hello')->setStatus(BlogCommentStatus::Pending);
        $this->privateRunner->run(fn () => $this->private->persist($comment));

        return $comment;
    }

    private function comments(): BlogCommentManager
    {
        return new BlogCommentManager($this->private, new BlogCommentRepository($this->privateRegistry), transactionRunner: $this->privateRunner);
    }

    private function answer(Request $request, Throwable $failure): Response
    {
        $request->attributes->set('_controller', 'Nowo\\BlogKitBundle\\Controller\\Admin\\BlogCommentController::approve');
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        new BlogKitConflictSubscriber()->onKernelException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);

        return $response;
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
