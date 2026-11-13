<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Tests\Integration\Worker\Entity\HostNote;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\BlogKitBundle\Worker\BlogKitOwnEntityManagerSubscriber;
use Nowo\BlogKitBundle\Worker\UnitOfWorkBoundaries;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function memory_get_usage;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-RUNTIME-002 (1.9.0) through ONE kept-alive kernel (FrankenPHP worker mode, kernel reset false): the
 * `http_kernel` service is driven directly (`handle()` + `terminate()`) and the services resetter never runs.
 *
 * - Two users alternate over {@see self::ITERATIONS} admin listings (`object_access: owner`, or the host's twin policy)
 *   and each sees only their own article; memory stays flat (margin {@see self::MEMORY_MARGIN}).
 * - DDD host (`doctrine.entity_manager`): the bundle's own manager holds no entity after each request, a title changed
 *   behind Doctrine's back is read fresh, a closed own manager is recreated, the host's manager keeps its pending change.
 * - Standard host: the host's default manager is never cleared by the bundle (rule 7: that is the host's job).
 */
final class WorkerKeptAliveKernelTest extends TestCase
{
    private const int ITERATIONS = 1000;

    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

    private ?WorkerTestKernel $kernel = null;

    private string $directory = '';

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        new Filesystem()->remove([$this->directory, sys_get_temp_dir() . '/nowo-blog-kit-worker']);
    }

    public function testDddHostTwoUsersAlternateWithIsolationFreshReadsAndFlatMemory(): void
    {
        $container = $this->boot(WorkerTestKernel::DDD);
        $blog      = $this->manager($container, 'blog');
        $host      = $this->manager($container, 'default');
        $this->seed($blog, new BlogUserRef('u-alice'), new BlogUserRef('u-bob'));
        $note = new HostNote('pending host change');
        $host->persist($note);

        $kernel     = $this->httpKernel($container);
        $connection = $blog->getConnection();
        $baseline   = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $user  = $i % 2 === 0 ? 'alice' : 'bob';
            $other = $user === 'alice' ? 'bob' : 'alice';
            // Changed behind Doctrine's back: a manager kept across requests would serve the old title.
            $connection->executeStatement('UPDATE content_blog_article_translation SET title = ? WHERE title LIKE ?', ['Post of ' . $user . ' #' . $i, 'Post of ' . $user . '%']);

            $html = $this->listing($kernel, $user);
            self::assertStringContainsString('Post of ' . $user . ' #' . $i, $html, 'request ' . $i . ' reads the fresh title');
            self::assertStringNotContainsString('Post of ' . $other, $html, 'request ' . $i . ' shows only ' . $user . '\'s article');
            self::assertSame(0, $blog->getUnitOfWork()->size(), 'no blog entity survives request ' . $i);
            if ($i === 50) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat over many requests');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), 'the host manager is never flushed nor cleared');
    }

    public function testDddHostClosedOwnManagerIsRecreatedAndMessengerBoundariesClear(): void
    {
        $container = $this->boot(WorkerTestKernel::DDD);
        $blog      = $this->manager($container, 'blog');
        $this->seed($blog, new BlogUserRef('u-alice'), new BlogUserRef('u-bob'));
        $blog->close();

        self::assertStringContainsString('Post of alice', $this->listing($this->httpKernel($container), 'alice'));
        $blog = $this->manager($container, 'blog');
        self::assertTrue($blog->isOpen());

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new stdClass(), UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $blog->getRepository(BlogArticle::class)->findAll();
        self::assertGreaterThan(0, $blog->getUnitOfWork()->size());
        $dispatcher->dispatch(new stdClass(), UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED);
        self::assertSame(0, $blog->getUnitOfWork()->size(), 'the message boundary clears the own manager');
    }

    /**
     * REQ-ARCH-001 rule 6: in the DDD host a bundle write flushes only the bundle's own manager; the host's pending
     * change is neither written nor cleared.
     */
    public function testDddHostBundleWritesNeverFlushTheHostManager(): void
    {
        $container = $this->boot(WorkerTestKernel::DDD);
        $blog      = $this->manager($container, 'blog');
        $host      = $this->manager($container, 'default');
        $this->seed($blog, new BlogUserRef('u-alice'), new BlogUserRef('u-bob'));
        $note = new HostNote('pending host change');
        $host->persist($note);

        $test = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $test);
        $comments = $test->get(BlogCommentManager::class);
        self::assertInstanceOf(BlogCommentManager::class, $comments);
        $article = $blog->getRepository(BlogArticle::class)->findOneBy(['slug' => 'post-alice']);
        self::assertInstanceOf(BlogArticle::class, $article);

        $comment = $comments->createPublicComment($article, 'Reader', 'reader@example.test', 'Nice post', Request::create('/blog/post-alice/comments', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']));
        $reply   = $comments->createStaffReply($comment, new BlogUserRef('u-alice'), 'Thanks');

        $connection = $blog->getConnection();
        self::assertSame('u-alice', $connection->fetchOne('SELECT staff_author_id FROM content_blog_comment WHERE id = ?', [$reply->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM worker_host_note'), 'the host change was not flushed');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), 'nor cleared');
    }

    public function testStandardHostTwoUsersAlternateAndTheHostManagerIsNeverCleared(): void
    {
        $container = $this->boot(WorkerTestKernel::STANDARD);
        self::assertFalse($container->has(BlogKitOwnEntityManagerSubscriber::class));
        $default = $this->manager($container, 'default');
        $alice   = new MappedTestUser()->setEmail('alice@example.test');
        $bob     = new MappedTestUser()->setEmail('bob@example.test');
        $default->persist($alice);
        $default->persist($bob);
        $default->flush();
        self::assertSame(1, $alice->getId());
        $this->seed($default, $alice, $bob);
        $note = new HostNote('pending host change');
        $default->persist($note);
        $kernel = $this->httpKernel($container);

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $user  = $i % 2 === 0 ? 'alice' : 'bob';
            $other = $user === 'alice' ? 'bob' : 'alice';
            $html  = $this->listing($kernel, $user);
            self::assertStringContainsString('Post of ' . $user, $html, 'request ' . $i);
            self::assertStringNotContainsString('Post of ' . $other, $html, 'request ' . $i . ' shows only ' . $user . '\'s article');
            if ($i === 50) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat over many requests');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note), 'the bundle never clears the host default manager (rule 7)');
    }

    private function boot(string $mode): ContainerInterface
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-worker-db-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);
        $this->kernel = new WorkerTestKernel($mode, $this->directory);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        foreach ($doctrine->getManagers() as $manager) {
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }

        return $container;
    }

    private function manager(ContainerInterface $container, string $name): EntityManagerInterface
    {
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function seed(EntityManagerInterface $manager, BlogUserInterface $alice, BlogUserInterface $bob): void
    {
        foreach (['alice' => $alice, 'bob' => $bob] as $name => $owner) {
            $article = new BlogArticle()->setSlug('post-' . $name)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
            $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle('Post of ' . $name));
            $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('Post of ' . $name));
            $article->setCreatedBy($owner);
            $manager->persist($article);
        }

        $manager->flush();
        $manager->clear();
    }

    private function httpKernel(ContainerInterface $container): HttpKernelInterface
    {
        $kernel = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        return $kernel;
    }

    private function listing(HttpKernelInterface $kernel, string $user): string
    {
        $request = Request::create('/admin/blog');
        $request->headers->set('X-Worker-User', $user);
        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        $html = (string) $response->getContent();
        self::assertTrue(str_contains($html, 'post-'), 'the listing renders articles');

        return $html;
    }

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
