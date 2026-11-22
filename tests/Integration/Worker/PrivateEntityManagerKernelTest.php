<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Tests\Integration\Worker\Entity\HostNote;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\BlogKitBundle\Worker\UnitOfWorkBoundaries;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function gc_collect_cycles;
use function implode;
use function intdiv;
use function memory_get_usage;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Since 1.12.0 (REQ-ARCH-001 rule 6, REQ-RUNTIME-002): the bundle's private entity manager through ONE kept-alive
 * kernel (FrankenPHP worker mode, kernel reset false: `handle()` + `terminate()`, the services resetter never runs).
 * Since 2.0 it is the zero-config default ({@see WorkerTestKernel::STANDARD}); the 1.12 opt-in `true` is a no-op.
 *
 * - The private manager is dropped at every request, Messenger and console boundary, and replaced when closed.
 * - A blog write never flushes nor clears your manager: your pending change stays pending; your user entity assigned
 *   to a blog row becomes a reference of the private manager.
 * - Repositories (also through your manager's getRepository(), as the entity value resolver uses it) and the admin
 *   tag choice read through the private manager.
 */
final class PrivateEntityManagerKernelTest extends TestCase
{
    use CollectsDeprecations;

    private const int ITERATIONS = 600;

    /** Without the harness growth (KeptAliveMemory) a clean run moves by a few hundred bytes; a 1 KB-per-request leak by 160 KB or more. */
    private const int MEMORY_MARGIN = 128 * 1024;

    private ?WorkerTestKernel $kernel = null;

    private string $directory = '';

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        new Filesystem()->remove([$this->directory, sys_get_temp_dir() . '/nowo-blog-kit-worker']);
    }

    #[Test]
    public function everyRequestDropsThePrivateManagerAndYourManagerIsNeverTouched(): void
    {
        $container     = $this->boot(WorkerTestKernel::STANDARD);
        $default       = $this->manager($container);
        [$alice, $bob] = $this->users($default);
        $this->seed($default, $alice, $bob);
        $note = new HostNote('pending host change');
        $default->persist($note);
        $private = $this->privateManager($container);
        $kernel  = $this->httpKernel($container);

        $samples = [];
        KeptAliveMemory::run(function () use ($kernel, $private, &$samples): void {
            for ($i = 0; $i < self::ITERATIONS; ++$i) {
                $user  = $i % 2 === 0 ? 'alice' : 'bob';
                $other = $user === 'alice' ? 'bob' : 'alice';
                $html  = $this->listing($kernel, $user);
                self::assertStringContainsString('Post of ' . $user, $html, 'request ' . $i);
                self::assertStringNotContainsString('Post of ' . $other, $html, 'request ' . $i . ' shows only ' . $user . '\'s article');
                self::assertFalse($private->isCreated(), 'the private manager is dropped at the end of request ' . $i);
                $this->sample($i, $samples);
            }
        });

        self::assertLessThan(self::MEMORY_MARGIN, $this->growth($samples), 'memory stays flat over many requests (samples: ' . implode(', ', $samples) . ')');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note), 'your manager is never flushed nor cleared');
        self::assertSame([], $default->getUnitOfWork()->getIdentityMap()[BlogArticle::class] ?? [], 'the listings never loaded an article into your manager');
    }

    #[Test]
    public function blogWritesKeepYourPendingChangesAndReferenceYourUsers(): void
    {
        $container     = $this->boot(WorkerTestKernel::STANDARD);
        $default       = $this->manager($container);
        [$alice, $bob] = $this->users($default);
        $this->seed($default, $alice, $bob);
        $note = new HostNote('pending host change');
        $default->persist($note);
        $private = $this->privateManager($container);
        $runner  = $this->service($container, BlogKitTransactionRunner::class);

        $deprecations = self::deprecationsOf(function () use ($container, $private, $runner, $alice): void {
            $article = $private->getRepository(BlogArticle::class)->findOneBy(['slug' => 'post-alice']);
            self::assertInstanceOf(BlogArticle::class, $article);
            $comments = $this->service($container, BlogCommentManager::class);
            $comment  = $comments->createPublicComment($article, 'Reader', 'reader@example.test', 'Nice post', Request::create('/blog/post-alice/comments', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']));
            // Your manager's instance of the user: the private manager stores a reference to the same row.
            $comments->createStaffReply($comment, $alice, 'Thanks');

            $runner->run(static function () use ($private, $alice): void {
                $new = new BlogArticle()->setSlug('new-post')->setPublishedAt(new DateTimeImmutable('2026-02-01'));
                $new->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('New post'));
                $new->setCreatedBy($alice);
                $private->persist($new);
            });
        });

        $connection = $default->getConnection();
        self::assertSame([], $deprecations, 'no probe in the private mode');
        self::assertSame(1, (int) $connection->fetchOne('SELECT staff_author_id FROM content_blog_comment WHERE staff_author_id IS NOT NULL'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT created_by_id FROM content_blog_article WHERE slug = 'new-post'"));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM worker_host_note'), 'your pending change was not flushed');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note), 'nor cleared');
        self::assertTrue($default->contains($alice), 'your user stays managed by your manager');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM blog_kit_test_user WHERE id = 1'), 'and is never inserted again');
    }

    #[Test]
    public function repositoriesAndTheTagChoiceReadThroughThePrivateManager(): void
    {
        $container     = $this->boot(WorkerTestKernel::STANDARD);
        $default       = $this->manager($container);
        [$alice, $bob] = $this->users($default);
        $this->seed($default, $alice, $bob);
        $tag = new BlogTag()->setSlug('php');
        $default->persist($tag);
        $default->flush();
        $default->clear();
        $private = $this->privateManager($container);

        // The entity value resolver of the admin routes asks your manager for the repository.
        $article = $default->getRepository(BlogArticle::class)->findOneBy(['slug' => 'post-alice']);
        self::assertInstanceOf(BlogArticle::class, $article);
        self::assertTrue($private->contains($article));
        self::assertFalse($default->contains($article));

        $tags = $default->getRepository(BlogTag::class)->createQueryBuilder('t')->getQuery()->getResult();
        self::assertCount(1, $tags);
        self::assertInstanceOf(BlogTag::class, $tags[0]);
        $article->addTag($tags[0]);
        $this->service($container, BlogKitTransactionRunner::class)->flushPending();

        self::assertSame(1, (int) $default->getConnection()->fetchOne('SELECT COUNT(*) FROM content_blog_article_tag'));
        self::assertSame(0, $default->getUnitOfWork()->size(), 'your manager loaded nothing');
    }

    #[Test]
    public function aClosedPrivateManagerIsReplacedAndMessengerBoundariesDropIt(): void
    {
        $container     = $this->boot(WorkerTestKernel::STANDARD);
        $default       = $this->manager($container);
        [$alice, $bob] = $this->users($default);
        $this->seed($default, $alice, $bob);
        $private = $this->privateManager($container);

        $closed = $private->live();
        $closed->close();
        self::assertStringContainsString('Post of alice', $this->listing($this->httpKernel($container), 'alice'));
        self::assertNotSame($closed, $private->live(), 'a closed private manager is replaced');
        self::assertTrue($default->isOpen());

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new stdClass(), UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $private->getRepository(BlogArticle::class)->findAll();
        self::assertTrue($private->isCreated());
        $dispatcher->dispatch(new stdClass(), UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED);
        self::assertFalse($private->isCreated(), 'the message boundary drops the private manager');
    }

    #[Test]
    public function theSettingsInsertRolledBackWithYourTransactionMakesThePrivateManagerStale(): void
    {
        $container  = $this->boot(WorkerTestKernel::STANDARD);
        $default    = $this->manager($container);
        $private    = $this->privateManager($container);
        $settings   = $this->service($container, BlogSettingsRepository::class);
        $connection = $default->getConnection();

        $connection->beginTransaction();
        $settings->getSingleton();
        self::assertFalse($private->isStale());
        $connection->rollBack();

        self::assertTrue($private->isStale(), 'the rolled-back settings row is not served from memory');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_blog_settings'));
    }

    /**
     * Since 2.0 (XS-I2): a user of yours persisted but not flushed fails the blog write with a clear message, before
     * anything is written; your manager keeps it pending, and once you flushed it the same write works.
     */
    #[Test]
    public function aUserYouHaveNotFlushedYetFailsClearlyAndNothingIsWritten(): void
    {
        $container = $this->boot(WorkerTestKernel::STANDARD);
        $default   = $this->manager($container);
        $private   = $this->privateManager($container);
        $runner    = $this->service($container, BlogKitTransactionRunner::class);
        $user      = new MappedTestUser()->setEmail('new@example.test');
        $default->persist($user);
        $write = static function () use ($private, $user): void {
            $article = new BlogArticle()->setSlug('draft')->setPublishedAt(new DateTimeImmutable('2026-02-01'));
            $article->setCreatedBy($user);
            $private->persist($article);
        };

        try {
            $runner->run($write);
            self::fail('The blog write must fail.');
        } catch (LogicException $exception) {
            self::assertSame('The ' . MappedTestUser::class . ' "createdBy" of a ' . BlogArticle::class . ' has no identifier yet: save (flush) your user before the blog writes for it; the blog entity manager only stores a reference to a user row that exists. Nothing was written.', $exception->getMessage());
        }

        $connection = $default->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_blog_article'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM blog_kit_test_user'), 'your user was not inserted by the bundle');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($user), 'and is still pending on your manager');

        $default->flush();
        $runner->run($write);
        self::assertSame((int) $user->getId(), (int) $connection->fetchOne("SELECT created_by_id FROM content_blog_article WHERE slug = 'draft'"));
    }

    /**
     * The 1.12 opt-in `doctrine.private_entity_manager: true` is a deprecated no-op since 2.0: the same private manager,
     * plus the deprecation of the key when the container is built.
     */
    #[Test]
    public function explicitTrueIsADeprecatedNoOp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-worker-db-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);
        $this->kernel = new WorkerTestKernel(WorkerTestKernel::PRIVATE, $this->directory);

        self::assertSame(
            ['Since nowo-tech/blog-kit-bundle 2.0: The "private_entity_manager" option at "nowo_blog_kit.doctrine" has no effect since 2.0 (without doctrine.entity_manager the private entity manager is the only mode): remove it. The key is removed in 3.0.'],
            // Once per processing of the configuration while the container is built (never per request).
            array_values(array_unique(self::deprecationsOf(fn () => $this->kernel?->boot()))),
        );
        $container = $this->kernel->getContainer();
        $private   = $this->service($container, NowoBlogKitExtension::ENTITY_MANAGER_SERVICE, PrivateEntityManager::class);
        self::assertSame($this->privateManager($container), $private);
    }

    private function boot(string $mode): ContainerInterface
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-worker-db-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);
        $this->kernel = new WorkerTestKernel($mode, $this->directory);
        // Since 2.0 the zero-config host builds its container without any notice (no host mode left).
        self::assertSame([], self::deprecationsOf(fn () => $this->kernel?->boot()));
        $container = $this->kernel->getContainer();

        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        foreach ($doctrine->getManagers() as $manager) {
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }

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

    private function privateManager(ContainerInterface $container): PrivateEntityManager
    {
        return $this->service($container, NowoBlogKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE, PrivateEntityManager::class);
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

    /**
     * @return array{0: MappedTestUser, 1: MappedTestUser}
     */
    private function users(EntityManagerInterface $default): array
    {
        $alice = new MappedTestUser()->setEmail('alice@example.test');
        $bob   = new MappedTestUser()->setEmail('bob@example.test');
        $default->persist($alice);
        $default->persist($bob);
        $default->flush();
        self::assertSame(1, $alice->getId());

        return [$alice, $bob];
    }

    /** Seeded through your manager (it maps the blog entities too); only the articles are detached afterwards. */
    private function seed(EntityManagerInterface $manager, MappedTestUser $alice, MappedTestUser $bob): void
    {
        $articles = [];
        foreach (['alice' => $alice, 'bob' => $bob] as $name => $owner) {
            $article = new BlogArticle()->setSlug('post-' . $name)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
            $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle('Post of ' . $name));
            $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('Post of ' . $name));
            $article->setCreatedBy($owner);
            $manager->persist($article);
            $articles[] = $article;
        }

        $manager->flush();
        foreach ($articles as $article) {
            foreach ($article->getTranslations() as $translation) {
                $manager->detach($translation);
            }

            $manager->detach($article);
        }
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

        return (string) $response->getContent();
    }

    /**
     * @param list<int> $samples
     */
    private function sample(int $request, array &$samples): void
    {
        if ($request >= 50 && $request % 50 === 0) {
            gc_collect_cycles();
            $samples[] = memory_get_usage();
        }
    }

    /**
     * @param list<int> $samples
     */
    private function growth(array $samples): int
    {
        $half = intdiv(count($samples), 2);

        return $this->median(array_slice($samples, $half)) - $this->median(array_slice($samples, 0, $half));
    }

    /**
     * @param list<int> $values
     */
    private function median(array $values): int
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
