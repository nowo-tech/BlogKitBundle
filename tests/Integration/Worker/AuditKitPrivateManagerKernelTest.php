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
use Nowo\BlogKitBundle\Tests\Integration\Worker\Entity\HostNote;
use Nowo\BlogKitBundle\Tests\Support\AuditedTestUser;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function sys_get_temp_dir;
use function uniqid;

/**
 * Since 2.0 (lesson W1-2, AuditKit 2.x interplay): `BlogArticle` implements AuditKit's `AuditableInterface`, and
 * AuditKit's blame listener runs on the bundle's private manager (they share your manager's event manager). Through a
 * real kernel with NowoAuditKitBundle 2.x (`blame_mode: reference`, `attribute_opt_in: true`) and zero Doctrine
 * configuration for the blog:
 *
 * - blame and timestamps are set on an article written through the private manager by your security user, held by
 *   your manager initialised and as an uninitialised proxy (the proxy is never loaded);
 * - a user you have not flushed yet fails clearly and nothing is written;
 * - neither AuditKit nor the bundle flushes your manager; your pending change stays pending.
 */
final class AuditKitPrivateManagerKernelTest extends TestCase
{
    use CollectsDeprecations;

    private ?WorkerTestKernel $kernel = null;

    private string $directory = '';

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        new Filesystem()->remove([$this->directory, sys_get_temp_dir() . '/nowo-blog-kit-worker']);
    }

    #[Test]
    public function blameAndTimestampsAreSetThroughThePrivateManagerWithoutFlushingYours(): void
    {
        $container = $this->boot();
        $default   = $this->manager($container);
        $alice     = new AuditedTestUser()->setEmail('alice@example.test');
        $default->persist($alice);
        $default->flush();
        $aliceId = (int) $alice->getId();
        $blog    = $this->service($container, NowoBlogKitExtension::ENTITY_MANAGER_SERVICE, EntityManagerInterface::class);
        $runner  = $this->service($container, BlogKitTransactionRunner::class, BlogKitTransactionRunner::class);
        $flushes = $this->countYourFlushes($default);
        $note    = new HostNote('pending host change');
        $default->persist($note);

        // Your manager holds the initialised user.
        $this->login($container, $alice);
        $this->write($runner, $blog, 'initialised');

        // Your manager holds only an uninitialised proxy of it.
        $default->detach($alice);
        $proxy = $default->getReference(AuditedTestUser::class, $aliceId);
        self::assertInstanceOf(AuditedTestUser::class, $proxy);
        self::assertTrue($default->getUnitOfWork()->isUninitializedObject($proxy));
        $this->login($container, $proxy);
        $this->write($runner, $blog, 'proxy');
        self::assertTrue($default->getUnitOfWork()->isUninitializedObject($proxy), 'the proxy was never loaded');

        // An update stamps updated_by / updated_at too.
        $article = $blog->getRepository(BlogArticle::class)->findOneBy(['slug' => 'initialised']);
        self::assertInstanceOf(BlogArticle::class, $article);
        $article->setPosition(3);
        $runner->flushPending();

        $connection = $default->getConnection();
        foreach (['initialised', 'proxy'] as $slug) {
            $row = $connection->fetchAssociative('SELECT created_by_id, updated_by_id, created_at, updated_at FROM content_blog_article WHERE slug = ?', [$slug]);
            self::assertIsArray($row, $slug);
            self::assertSame($aliceId, (int) $row['created_by_id'], $slug . ': created_by is your user');
            self::assertSame($aliceId, (int) $row['updated_by_id'], $slug . ': updated_by is your user');
            self::assertNotNull($row['created_at'], $slug);
            self::assertNotNull($row['updated_at'], $slug);
        }

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM blog_kit_audited_user'), 'your user is never inserted again');
        self::assertSame(0, $flushes->count, 'neither AuditKit nor the bundle flushed your manager');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note), 'your pending change is still pending');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM worker_host_note'));
    }

    #[Test]
    public function aSecurityUserYouHaveNotFlushedFailsClearlyAndNothingIsWritten(): void
    {
        $container = $this->boot();
        $default   = $this->manager($container);
        $blog      = $this->service($container, NowoBlogKitExtension::ENTITY_MANAGER_SERVICE, EntityManagerInterface::class);
        $runner    = $this->service($container, BlogKitTransactionRunner::class, BlogKitTransactionRunner::class);
        $unsaved   = new AuditedTestUser()->setEmail('new@example.test');
        $default->persist($unsaved);
        $this->login($container, $unsaved);

        try {
            $this->write($runner, $blog, 'refused');
            self::fail('The blog write must fail.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('has no identifier yet: save (flush) your user before the blog writes for it', $exception->getMessage());
        }

        $connection = $default->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_blog_article'), 'nothing was written');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM blog_kit_audited_user'));
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($unsaved), 'your user is still pending on your manager');
    }

    private function write(BlogKitTransactionRunner $runner, EntityManagerInterface $blog, string $slug): void
    {
        $runner->run(static function () use ($blog, $slug): void {
            $article = new BlogArticle()->setSlug($slug)->setPublishedAt(new DateTimeImmutable('2026-04-01'));
            $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle($slug));
            $blog->persist($article);
        });
    }

    private function login(ContainerInterface $container, AuditedTestUser $user): void
    {
        $this->service($container, 'security.token_storage', TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function countYourFlushes(EntityManagerInterface $default): object
    {
        $counter = new class($default) {
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
        $default->getEventManager()->addEventListener([Events::onFlush], $counter);

        return $counter;
    }

    private function boot(): ContainerInterface
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-auditkit-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);
        $this->kernel = new WorkerTestKernel(WorkerTestKernel::STANDARD, $this->directory, null, null, 'reference');
        self::assertSame([], self::deprecationsOf(fn () => $this->kernel?->boot()));
        $container = $this->kernel->getContainer();

        $manager = $this->manager($container);
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());

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
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(ContainerInterface $container, string $id, string $class): object
    {
        $test = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $test);
        $service = $test->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
