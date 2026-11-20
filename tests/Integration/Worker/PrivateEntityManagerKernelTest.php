<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Doctrine\BlogKitPrivateUserReferenceListener;
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
use function count;
use function gc_collect_cycles;
use function implode;
use function intdiv;
use function memory_get_usage;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Since 1.12.0 (REQ-ARCH-001 rule 6, REQ-RUNTIME-002): `doctrine.private_entity_manager: true` through ONE kept-alive
 * kernel (FrankenPHP worker mode, kernel reset false: `handle()` + `terminate()`, the services resetter never runs),
 * and the host-flush probe of the host mode.
 *
 * - The private manager is dropped at every request, Messenger and console boundary, and replaced when closed.
 * - A blog write never flushes nor clears your manager: your pending change stays pending; your user entity assigned
 *   to a blog row becomes a reference of the private manager.
 * - Repositories (also through your manager's getRepository(), as the entity value resolver uses it) and the admin
 *   tag choice read through the private manager.
 * - Host mode: a flush of your manager by the bundle reports your pending change once per class and unit of work.
 */
final class PrivateEntityManagerKernelTest extends TestCase
{
    use CollectsDeprecations;

    private const int ITERATIONS = 600;

    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

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
        $container     = $this->boot(WorkerTestKernel::PRIVATE);
        $default       = $this->manager($container);
        [$alice, $bob] = $this->users($default);
        $this->seed($default, $alice, $bob);
        $note = new HostNote('pending host change');
        $default->persist($note);
        $private = $this->privateManager($container);
        $kernel  = $this->httpKernel($container);

        $samples = [];
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $user  = $i % 2 === 0 ? 'alice' : 'bob';
            $other = $user === 'alice' ? 'bob' : 'alice';
            $html  = $this->listing($kernel, $user);
            self::assertStringContainsString('Post of ' . $user, $html, 'request ' . $i);
            self::assertStringNotContainsString('Post of ' . $other, $html, 'request ' . $i . ' shows only ' . $user . '\'s article');
            self::assertFalse($private->isCreated(), 'the private manager is dropped at the end of request ' . $i);
            $this->sample($i, $samples);
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->growth($samples), 'memory stays flat over many requests (samples: ' . implode(', ', $samples) . ')');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note), 'your manager is never flushed nor cleared');
        self::assertSame([], $default->getUnitOfWork()->getIdentityMap()[BlogArticle::class] ?? [], 'the listings never loaded an article into your manager');
    }

    #[Test]
    public function blogWritesKeepYourPendingChangesAndReferenceYourUsers(): void
    {
        $container     = $this->boot(WorkerTestKernel::PRIVATE);
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
        $container     = $this->boot(WorkerTestKernel::PRIVATE);
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
        $container     = $this->boot(WorkerTestKernel::PRIVATE);
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
        $container  = $this->boot(WorkerTestKernel::PRIVATE);
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

    #[Test]
    public function aNewUserWithoutIdentifierIsLeftToDoctrine(): void
    {
        $container = $this->boot(WorkerTestKernel::PRIVATE);
        $private   = $this->privateManager($container);
        $user      = new MappedTestUser()->setEmail('new@example.test');
        $article   = new BlogArticle()->setSlug('draft');
        $article->setCreatedBy($user);
        $live = $private->live();
        $live->persist($article);

        new BlogKitPrivateUserReferenceListener()->preFlush(new PreFlushEventArgs($live));

        self::assertSame($user, $article->getCreatedBy(), 'nothing to reference: Doctrine reports the new entity itself');
    }

    #[Test]
    public function hostModeReportsYourPendingChangesOncePerUnitOfWork(): void
    {
        $container = $this->boot(WorkerTestKernel::STANDARD);
        $default   = $this->manager($container);
        $runner    = $this->service($container, BlogKitTransactionRunner::class);
        $message   = 'Your pending changes to ' . HostNote::class . ' were flushed by NowoBlogKitBundle; 2.0 no longer does.';

        $default->persist(new HostNote('first'));
        self::assertSame([$message], self::deprecationsOf(static fn () => $runner->flushPending()));
        $default->persist(new HostNote('second'));
        self::assertSame([], self::deprecationsOf(static fn () => $runner->flushPending()), 'once per class and unit of work');
        self::assertSame(2, (int) $default->getConnection()->fetchOne('SELECT COUNT(*) FROM worker_host_note'), '1.x behaviour: your changes are written');

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new stdClass(), UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $default->persist(new HostNote('third'));
        self::assertSame([$message], self::deprecationsOf(static fn () => $runner->flushPending()), 'a new unit of work reports again');
    }

    private function boot(string $mode): ContainerInterface
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-worker-db-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);
        $this->kernel = new WorkerTestKernel($mode, $this->directory);
        // The host mode kernel (no option) triggers the 1.12.0 notice when its container is built.
        self::deprecationsOf(fn () => $this->kernel?->boot());
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
