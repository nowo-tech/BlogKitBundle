<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Event\BlogArticlePublishedEvent;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Service\BlogTagRegistry;
use Nowo\BlogKitBundle\Tests\Integration\Worker\Entity\HostNote;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\Tests\Support\HostRef\HostArticleLink;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

use function sys_get_temp_dir;
use function uniqid;

/**
 * Since 2.0 (REQ-ARCH-001 rule 6), THE data-loss test of the flip, through a real kernel with ZERO Doctrine
 * configuration for the bundle (no `private_entity_manager`, no `entity_manager`: the 1.x default, where every blog
 * write flushed your default manager) and real database writes, nothing mocked.
 *
 * Your application leaves changes pending on its own manager (a new entity scheduled for insertion, an edited managed
 * entity, an entity persisted by your listener of {@see BlogArticlePublishedEvent}, the "Beacon pattern"), then the
 * bundle writes: a unit of the runner, a public comment, a staff reply by your (edited) user, a moderation of a comment
 * YOUR manager loaded (W1-2), a tag sync, an admin form save, a failed blog write, an admin request and a Messenger
 * boundary. The test proves that:
 *
 * - every blog write is committed, through the bundle's private manager (never through yours);
 * - your manager is never flushed by the bundle (no flush event of it at all), never cleared, never closed;
 * - each of your pending changes is still pending afterwards, with its change set intact, and none reached the
 *   database: in 1.x every one of them would have been written by the first blog write;
 * - your own flush then writes all of them: nothing was lost, the bundle simply no longer saves them for you;
 * - no deprecation is triggered (the 1.12 notice and host-flush probe are gone).
 *
 * Runs on SQLite ({@see HostPendingChangesKernelTest}) and on the real servers of the database group
 * (`tests/Database/HostPendingChangesDatabaseTest`, BLOG_KIT_DATABASE_URL).
 */
abstract class HostPendingChangesTestCase extends TestCase
{
    use CollectsDeprecations;

    private ?WorkerTestKernel $kernel = null;

    private string $directory = '';

    private ?string $databaseUrl = null;

    /** Null: SQLite in the test's directory. */
    abstract protected function databaseUrl(): ?string;

    protected function tearDown(): void
    {
        if ($this->kernel instanceof WorkerTestKernel && $this->databaseUrl !== null) {
            // A real server keeps its tables: leave none of this test's (host tables with FKs to the blog ones) behind.
            $manager = $this->manager($this->kernel->getContainer());
            new SchemaTool($manager)->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        }

        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->directory !== '') {
            new Filesystem()->remove([$this->directory, sys_get_temp_dir() . '/nowo-blog-kit-worker']);
        }
    }

    #[Test]
    public function blogWritesNeverFlushYourPendingChangesAndYourFlushStillWritesThem(): void
    {
        $container = $this->boot();
        $default   = $this->manager($container);
        $alice     = new MappedTestUser()->setEmail('alice@example.test');
        $default->persist($alice);
        $default->persist(new MappedTestUser()->setEmail('bob@example.test'));
        $default->flush();
        $aliceId    = (int) $alice->getId();
        $connection = $default->getConnection();
        $runner     = $this->service($container, BlogKitTransactionRunner::class);

        // The bundle's entity manager is its private one: zero configuration, no notice.
        $blogManager = $this->service($container, NowoBlogKitExtension::ENTITY_MANAGER_SERVICE, EntityManagerInterface::class);
        self::assertInstanceOf(PrivateEntityManager::class, $blogManager);
        self::assertNotSame($default, $blogManager);
        $runner->run(static function () use ($blogManager, $alice): void {
            $article = new BlogArticle()->setSlug('seeded')->setPublishedAt(new DateTimeImmutable('2026-01-01'));
            $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('Seeded'));
            $article->setCreatedBy($alice);
            $blogManager->persist($article);
        });

        // Counts every flush of YOUR manager (the private one shares its event manager, so it is told apart).
        $yourFlushes = new class($default) {
            public int $count = 0;

            public function __construct(private readonly EntityManagerInterface $yours)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($args->getObjectManager() === $this->yours) {
                    ++$this->count;
                }
            }
        };
        $default->getEventManager()->addEventListener([Events::onFlush], $yourFlushes);

        // Your pending changes: a new entity, an edited managed one, and one your listener of a blog event persists.
        $note = new HostNote('pending host change');
        $default->persist($note);
        $alice->setEmail('alice@renamed.test');
        $fromListener = new HostNote('persisted by your listener');
        $dispatcher   = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BlogArticlePublishedEvent::class, static function () use ($default, $fromListener): void {
            $default->persist($fromListener);
        });

        $comments = $this->service($container, BlogCommentManager::class);
        $articles = $this->service($container, BlogArticleRepository::class);
        // Not used by a bundle service of this kernel (removed from the container): built as a host would build it.
        $tags = new BlogTagRegistry($blogManager, $this->service($container, BlogTagRepository::class), $this->service($container, BlogLocales::class), $runner);

        $yours        = null;
        $deprecations = self::blogKitDeprecationsOf(function () use ($container, $default, $blogManager, $runner, $comments, $articles, $tags, $alice, $dispatcher, &$yours): void {
            // A unit of the runner: a published article (its event runs your listener after the commit).
            $runner->run(static function () use ($blogManager): void {
                $article = new BlogArticle()->setSlug('launch')->setPublished(true)->setPublishedAt(new DateTimeImmutable('2026-03-01'));
                $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('Launch'));
                $blogManager->persist($article);
            });

            // A public comment, then a staff reply signed by your user (whose email you edited and did not flush).
            $post = $articles->findOneBy(['slug' => 'seeded']);
            self::assertInstanceOf(BlogArticle::class, $post);
            $comment = $comments->createPublicComment($post, 'Reader', 'reader@example.test', 'Nice post', Request::create('/blog/seeded/comments', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']));
            $comments->createStaffReply($comment, $alice, 'Thanks');

            // W1-2: a comment YOUR manager loaded is moderated like one of the bundle's (re-loaded by id, no duplicate).
            $yours = $default->find(BlogComment::class, (int) $comment->getId());
            self::assertInstanceOf(BlogComment::class, $yours);
            self::assertNotSame($comment, $yours);
            $comments->approve($yours, $alice);

            // A tag sync and an admin form save (bound changes flushed in one transaction).
            $tags->syncDefinitions(['php' => ['es' => 'PHP', 'en' => 'PHP']]);
            $post->setPosition(9);
            $runner->flushPending();

            // A failed blog write: rolled back, only the private manager is dropped.
            try {
                $runner->run(static function () use ($blogManager): never {
                    $blogManager->persist(new BlogArticle()->setSlug('rolled-back')->setPublishedAt(new DateTimeImmutable('2026-03-02')));

                    throw new RuntimeException('the blog write fails');
                });
                self::fail('The write must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame('the blog write fails', $exception->getMessage());
            }

            // An admin request (kept-alive kernel: handle + terminate) and the start of a Messenger message: the
            // bundle drops its private manager there, never yours. (The END of a handled message is left out on
            // purpose: Symfony's own DoctrineClearEntityManagerWorkerSubscriber clears every manager there, yours
            // included, in 1.x as in 2.0; that is the framework, not the bundle.)
            $this->adminListing($container);
            $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        });

        self::assertSame([], $deprecations, 'no notice and no host-flush probe in 2.0');

        // Every blog write is committed.
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM content_blog_article WHERE slug = 'launch'"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM content_blog_article WHERE slug = 'rolled-back'"));
        self::assertSame(9, (int) $connection->fetchOne("SELECT position FROM content_blog_article WHERE slug = 'seeded'"));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_blog_comment'), 'the comment and the reply, no duplicate');
        self::assertSame(BlogCommentStatus::Approved->value, $connection->fetchOne('SELECT status FROM content_blog_comment WHERE parent_id IS NULL'));
        self::assertSame($aliceId, (int) $connection->fetchOne('SELECT staff_author_id FROM content_blog_comment WHERE parent_id IS NOT NULL'), 'your user signs the reply (a reference, never a new row)');
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM content_blog_tag WHERE slug = 'php'"));

        // Your manager was never flushed, cleared or closed by the bundle, and none of your changes reached the database.
        self::assertSame(0, $yourFlushes->count, 'the bundle never flushed your manager');
        self::assertTrue($default->isOpen());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM worker_host_note'), 'no pending entity of yours was written');
        self::assertSame('alice@example.test', $connection->fetchOne('SELECT email FROM blog_kit_test_user WHERE id = ?', [$aliceId]), 'your edit was not written');
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM blog_kit_test_user'), 'your user was never inserted again');

        // ...and each of them is still pending, intact.
        $unitOfWork = $default->getUnitOfWork();
        self::assertTrue($unitOfWork->isScheduledForInsert($note), 'your new entity is still scheduled');
        self::assertTrue($unitOfWork->isScheduledForInsert($fromListener), 'the entity your listener persisted is still scheduled');
        self::assertTrue($default->contains($alice));
        $unitOfWork->computeChangeSets();
        self::assertSame(['alice@example.test', 'alice@renamed.test'], $unitOfWork->getEntityChangeSet($alice)['email'] ?? null, 'your edit is still pending');
        self::assertInstanceOf(BlogComment::class, $yours);
        self::assertFalse($unitOfWork->isScheduledForUpdate($yours), 'the comment your manager loaded has no pending change');

        // Your own flush writes them all: nothing was lost.
        $default->flush();
        self::assertSame(1, $yourFlushes->count);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM worker_host_note'));
        self::assertSame('alice@renamed.test', $connection->fetchOne('SELECT email FROM blog_kit_test_user WHERE id = ?', [$aliceId]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_blog_comment'), 'your flush writes no blog row again');
        self::assertSame(BlogCommentStatus::Approved->value, $connection->fetchOne('SELECT status FROM content_blog_comment WHERE parent_id IS NULL'), 'nor undoes the moderation');
    }

    /**
     * W1-2: an entity of yours pointing at a blog row through `$yourManager->getReference()` is written by your flush
     * (the FK), and a blog row loaded by YOUR manager handed to a bundle write is re-loaded, never inserted again.
     */
    #[Test]
    public function yourReferencesToBlogRowsAreWrittenByYourFlush(): void
    {
        $container   = $this->boot();
        $default     = $this->manager($container);
        $blogManager = $this->service($container, NowoBlogKitExtension::ENTITY_MANAGER_SERVICE, EntityManagerInterface::class);
        $runner      = $this->service($container, BlogKitTransactionRunner::class);
        $article     = new BlogArticle()->setSlug('linked')->setPublishedAt(new DateTimeImmutable('2026-05-01'));
        $runner->run(static function () use ($blogManager, $article): void {
            $blogManager->persist($article);
        });
        $id = (int) $article->getId();

        $link = new HostArticleLink($default->getReference(BlogArticle::class, $id));
        $default->persist($link);
        $default->flush();

        $connection = $default->getConnection();
        self::assertSame($id, (int) $connection->fetchOne('SELECT article_id FROM host_ref_article_link WHERE id = ?', [$link->id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_blog_article'), 'no duplicate blog row');

        // A blog row your manager still holds after it was deleted elsewhere fails clearly when handed to a bundle write.
        $comments = $this->service($container, BlogCommentManager::class);
        $comment  = $comments->createPublicComment($article, 'Reader', 'reader@example.test', 'Hi', Request::create('/blog/linked/comments', 'POST', server: ['REMOTE_ADDR' => '10.0.0.2']));
        $yours    = $default->find(BlogComment::class, (int) $comment->getId());
        self::assertInstanceOf(BlogComment::class, $yours);
        $moderator = new MappedTestUser()->setEmail('mod@example.test');
        $default->persist($moderator);
        $default->flush();
        $connection->executeStatement('DELETE FROM content_blog_comment');
        // A new unit of work (here: a Messenger message): the private manager starts empty.
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The blog comment #' . $comment->getId() . ' no longer exists.');
        $comments->approve($yours, $moderator);
    }

    private function boot(): ContainerInterface
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-host-pending-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);
        $this->databaseUrl = $this->databaseUrl();
        $this->kernel      = new WorkerTestKernel(WorkerTestKernel::STANDARD, $this->directory, $this->databaseUrl);
        self::assertSame([], self::blogKitDeprecationsOf(fn () => $this->kernel?->boot()), 'zero configuration: no notice');
        $container = $this->kernel->getContainer();

        $manager  = $this->manager($container);
        $tool     = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $container;
    }

    private function manager(ContainerInterface $container): EntityManagerInterface
    {
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     * @param class-string<T>|null $class
     *
     * @return T
     */
    private function service(ContainerInterface $container, string $id, ?string $class = null): object
    {
        $test = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $test);
        $service = $test->get($id);
        self::assertInstanceOf($class ?? $id, $service);

        return $service;
    }

    private function adminListing(ContainerInterface $container): void
    {
        $kernel = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);
        $request = Request::create('/admin/blog');
        $request->headers->set('X-Worker-User', 'alice');
        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
    }
}
