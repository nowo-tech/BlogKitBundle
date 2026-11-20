<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Command\SyncBlogHashtagsCommand;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitConflictSubscriber;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Nowo\BlogKitBundle\Service\BlogTagRegistry;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\Translator;
use Throwable;

use function array_map;
use function array_unshift;
use function implode;
use function sprintf;

/**
 * REQ-RUNTIME-003 races on a real server, two connections: the tag "only one" rule (registry and hashtag command),
 * the admin slug race, a real lock wait on a comment write (409 + CONFLICT_001) and a conflict after a commit of the
 * same request (CONFLICT_002).
 */
#[Group('database')]
final class ConcurrencyTest extends DatabaseTestCase
{
    #[Test]
    public function twoWritersCreatingTheSameTagEndWithOneTagAndTheReplayUpdatesIt(): void
    {
        $this->raceOnFirstFlush(fn (): int => $this->insertTagFromWriter('php', ['es' => 'PHP (A)', 'en' => 'PHP (A)']));

        $registry = new BlogTagRegistry($this->entityManager, new BlogTagRepository($this->registry), LocaleTestSupport::create(), $this->runner);
        $registry->syncDefinitions(['php' => ['es' => 'PHP', 'en' => 'PHP']]);

        self::assertSame(1, $this->registry->resets, 'the closed manager was replaced once, between the attempts');
        self::assertSame(1, (int) $this->blog->fetchOne("SELECT COUNT(*) FROM content_blog_tag WHERE slug = 'php'"));
        self::assertSame(
            [['locale' => 'en', 'name' => 'PHP'], ['locale' => 'es', 'name' => 'PHP']],
            $this->blog->fetchAllAssociative("SELECT tt.locale, tt.name FROM content_blog_tag_translation tt JOIN content_blog_tag t ON t.id = tt.translatable_id WHERE t.slug = 'php' ORDER BY tt.locale"),
            'the replay updated the winner\'s tag, without duplicate translations',
        );
        self::assertTrue($this->entityManager->isOpen());
        $this->assertConnectionUsable();
    }

    #[Test]
    public function theHashtagCommandLinksTheTagAConcurrentWriterCreated(): void
    {
        $article = $this->createArticle('launch');
        $article->getTranslationOrFallback('es')->setBody('<p>Body</p><p>#PHP</p>');
        $this->entityManager->flush();
        $articleId = $article->getId();

        $winner = 0;
        $this->raceOnFirstFlush(function () use (&$winner): void {
            $winner = $this->insertTagFromWriter('php', ['es' => 'PHP', 'en' => 'PHP']);
        });

        $tester = new CommandTester(new SyncBlogHashtagsCommand(
            new BlogArticleRepository($this->registry, LocaleTestSupport::create(), new BlogUsers(BlogUsers::ID)),
            new BlogTagRepository($this->registry),
            new BlogHashtagProcessor(new Translator('en'), $this->urlGenerator()),
            $this->entityManager,
            LocaleTestSupport::create(),
            $this->runner,
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, $this->registry->resets, 'the unit was replayed once on a fresh manager');
        self::assertStringContainsString('0 tags created', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '', 'the replay found the winner\'s tag instead of creating one');
        self::assertSame(1, (int) $this->blog->fetchOne("SELECT COUNT(*) FROM content_blog_tag WHERE slug = 'php'"));
        self::assertSame(
            [$winner],
            array_map(intval(...), $this->blog->fetchFirstColumn('SELECT blog_tag_id FROM content_blog_article_tag WHERE blog_article_id = ?', [$articleId])),
        );
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aSlugTakenByAConcurrentAdminIsAUniqueViolationNotAConflict(): void
    {
        $this->createArticle('template');
        $this->raceOnFirstFlush(fn () => $this->copyArticleFromWriter('template', 'same'));
        $this->entityManager->persist($this->newArticle('same'));

        try {
            $this->runner->flushPending();
            self::fail('The duplicate slug must fail the save.');
        } catch (TransientStorageFailure $failure) {
            self::fail('A unique violation is not a lock conflict: ' . $failure::class);
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame(1, (int) $this->blog->fetchOne("SELECT COUNT(*) FROM content_blog_article WHERE slug = 'same'"));
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aRealLockWaitOnACommentWriteAnswers409WithConflict001(): void
    {
        $comment = $this->createComment($this->createArticle('post'));
        $id      = (int) $comment->getId();
        $this->lockCommentRow($id);

        $failure = $this->catchFailure(fn () => $this->comments()->approve($comment, new BlogUserRef('mod-1')));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);

        $response = $this->answer(Request::create('/admin/blog/comments/' . $id . '/approve', 'POST'), $failure);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));

        $this->writer->rollBack();
        self::assertSame(BlogCommentStatus::Pending->value, $this->blog->fetchOne('SELECT status FROM content_blog_comment WHERE id = ?', [$id]), 'nothing half-saved');
        self::assertTrue($this->entityManager->isOpen(), 'the closed manager was replaced, an open one is never reset');
        $this->assertConnectionUsable();
    }

    #[Test]
    public function aConflictAfterACommitOfTheSameRequestAnswersConflict002WithoutRetryAfter(): void
    {
        $article = $this->createArticle('thread');
        $comment = $this->createComment($article);
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
        self::assertSame(BlogCommentStatus::Pending->value, $this->blog->fetchOne('SELECT status FROM content_blog_comment WHERE id = ?', [$id]));
        $this->assertConnectionUsable();
    }

    #[Test]
    public function thePublishEventFollowsTheCommitAndNeverARolledBackUnit(): void
    {
        $seen = [];
        $this->dispatcher->addListener(BlogArticlePublishedEvent::class, function (BlogArticlePublishedEvent $event) use (&$seen): void {
            $slug   = $event->getArticleSnapshot()->slug;
            $seen[] = [$slug, $this->blog->isTransactionActive(), (int) $this->writer->fetchOne('SELECT COUNT(*) FROM content_blog_article WHERE slug = ?', [$slug])];
        });

        $article = $this->newArticle('released');
        $this->runner->run(function () use ($article): void {
            $this->entityManager->persist($article);
        });
        self::assertSame([['released', false, 1]], $seen, 'after the commit: the other connection already reads the row');

        $this->entityManager->persist($this->newArticle('released'));
        $this->catchFailure(fn () => $this->runner->flushPending());
        self::assertCount(1, $seen, 'a rolled-back unit is never announced');
    }

    /**
     * Runs $race once, from the other connection, when the blog manager flushes for the first time: between the
     * unit's lookup and its insert, the window of a check-then-insert.
     */
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

    private function newArticle(string $slug): BlogArticle
    {
        $article = new BlogArticle()->setSlug($slug)->setPublished(true)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle($slug));

        return $article;
    }

    /**
     * The other admin's insert: a copy of the template article's row with another slug, from the other connection.
     */
    private function copyArticleFromWriter(string $templateSlug, string $slug): void
    {
        $columns = [];
        foreach ($this->writer->createSchemaManager()->listTableColumns('content_blog_article') as $column) {
            if ($column->getName() !== 'id') {
                $columns[] = $column->getName();
            }
        }

        $select = array_map(static fn (string $column): string => $column === 'slug' ? '? AS slug' : $column, $columns);
        $params = [$slug, $templateSlug];
        $id     = $this->writerId('content_blog_article');
        if ($id !== []) {
            array_unshift($columns, 'id');
            array_unshift($select, '? AS id');
            array_unshift($params, $id['id']);
        }

        $this->writer->executeStatement(
            sprintf('INSERT INTO content_blog_article (%s) SELECT %s FROM content_blog_article WHERE slug = ?', implode(', ', $columns), implode(', ', $select)),
            $params,
        );
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . '?' . http_build_query($parameters);
            }
        };
    }
}
