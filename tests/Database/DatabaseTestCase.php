<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\BlogKitBundle\Doctrine\BlogUserReferenceMetadataListener;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticlePublishedDoctrineSubscriber;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\ResettingManagerRegistry;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function dirname;
use function getenv;
use function is_string;

use const CASE_LOWER;

/**
 * REQ-RUNTIME-003 on a real server (CI job "Databases": MySQL 8.4, MariaDB 11.4, PostgreSQL 16; DBAL 4 and 3):
 * `BLOG_KIT_DATABASE_URL`, else skipped.
 *
 * Two connections on the same database: "writer" (A) plays the other request with raw SQL, "blog" (B) runs the
 * bundle's services on an entity manager (id user references, so only the bundle's entities are mapped) with a
 * {@see ResettingManagerRegistry} and B's commits counted by transaction-doctrine's {@see CommitLedger}. Both sessions
 * wait at most 1 second for a lock, so a hang fails fast. Subclasses carry #[Group('database')].
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $writer;
    protected Connection $blog;
    protected EntityManager $entityManager;
    protected ResettingManagerRegistry $registry;
    protected CommitLedger $ledger;
    protected EventDispatcher $dispatcher;
    protected BlogArticlePublishedDoctrineSubscriber $publishedEvents;
    protected BlogKitTransactionRunner $runner;

    protected function setUp(): void
    {
        $url = getenv('BLOG_KIT_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('BLOG_KIT_DATABASE_URL is not set (CI job "Databases" only).');
        }

        LocaleTestSupport::bindDefaults();
        if (!Type::hasType(BlogUserRefType::NAME)) {
            Type::addType(BlogUserRefType::NAME, BlogUserRefType::class);
        }

        $params        = new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse($url);
        $this->writer  = DriverManager::getConnection($params);
        $this->ledger  = new CommitLedger();
        $configuration = $this->ormConfiguration();
        $configuration->setMiddlewares([new CommitTrackingMiddleware($this->ledger)]);
        $this->blog = DriverManager::getConnection($params, $configuration);

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new BlogUserReferenceMetadataListener());
        $this->dispatcher      = new EventDispatcher();
        $this->publishedEvents = new BlogArticlePublishedDoctrineSubscriber($this->dispatcher);
        $events->addEventListener([Events::onFlush, Events::postFlush], $this->publishedEvents);

        $blog                = $this->blog;
        $this->entityManager = new EntityManager($blog, $configuration, $events);
        $this->registry      = new ResettingManagerRegistry(
            $this->entityManager,
            $blog,
            static function (EntityManager $ghost) use ($blog, $configuration, $events): void {
                $ghost->__construct($blog, $configuration, $events);
            },
        );

        $tool     = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        foreach ([$this->writer, $this->blog] as $connection) {
            $this->shortLockWait($connection);
        }

        $this->runner = new BlogKitTransactionRunner($this->entityManager, $this->registry, null, $this->publishedEvents);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }

            $this->writer->close();
            $this->blog->close();
        }
    }

    protected function isPostgreSql(): bool
    {
        return $this->blog->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    protected function createArticle(string $slug, bool $published = true): BlogArticle
    {
        $article = new BlogArticle()->setSlug($slug)->setPublished($published)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle($slug)->setBody('Body ' . $slug));
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    protected function createComment(BlogArticle $article, string $author = 'Ana'): BlogComment
    {
        $comment = new BlogComment()->setArticle($article)->setAuthorName($author)->setBody('Hello')->setStatus(BlogCommentStatus::Pending);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $comment;
    }

    /**
     * Starts the other request's transaction and locks the comment row (FOR UPDATE) until rollback.
     */
    protected function lockCommentRow(int $commentId): void
    {
        $this->writer->beginTransaction();
        $this->writer->fetchOne('SELECT id FROM content_blog_comment WHERE id = ? FOR UPDATE', [$commentId]);
    }

    /**
     * Inserts a tag and its translations from the other connection (auto-commit), as a concurrent writer would.
     *
     * @param array<string, string> $names locale => name
     */
    protected function insertTagFromWriter(string $slug, array $names): int
    {
        $this->writer->insert('content_blog_tag', $this->writerId('content_blog_tag') + ['slug' => $slug]);
        $id = (int) $this->writer->fetchOne('SELECT id FROM content_blog_tag WHERE slug = ?', [$slug]);

        foreach ($names as $locale => $name) {
            $this->writer->insert('content_blog_tag_translation', $this->writerId('content_blog_tag_translation') + ['translatable_id' => $id, 'locale' => $locale, 'name' => $name]);
        }

        return $id;
    }

    /**
     * The id of a raw insert: none with an identity column (MySQL, MariaDB, PostgreSQL on DBAL 4), the next value of
     * the table's sequence when the AUTO strategy is a sequence (PostgreSQL on DBAL 3).
     *
     * @return array<string, int>
     */
    protected function writerId(string $table): array
    {
        $metadata = null;
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $classMetadata) {
            if ($classMetadata->getTableName() === $table) {
                $metadata = $classMetadata;
            }
        }

        if ($metadata === null || $metadata->idGenerator->isPostInsertGenerator()) {
            return [];
        }

        $sequence = $metadata->sequenceGeneratorDefinition['sequenceName'] ?? $table . '_id_seq';

        return ['id' => (int) $this->writer->fetchOne($this->writer->getDatabasePlatform()->getSequenceNextValSQL($sequence))];
    }

    protected function assertConnectionUsable(): void
    {
        self::assertSame(0, $this->blog->getTransactionNestingLevel(), 'no transaction left open');
        self::assertSame(1, (int) $this->blog->fetchOne('SELECT 1'));
    }

    private function ormConfiguration(): Configuration
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));

        return $configuration;
    }

    private function shortLockWait(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

            return;
        }

        $connection->executeStatement("SET lock_timeout = '1s'");
    }
}
