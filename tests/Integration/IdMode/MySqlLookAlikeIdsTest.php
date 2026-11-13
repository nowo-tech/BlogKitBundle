<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\IdMode;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\BlogKitBundle\Doctrine\BlogUserReferenceMetadataListener;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\TestManagerRegistry;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function getenv;
use function is_string;
use function sort;

use const CASE_LOWER;

/**
 * REQ-SEC-006 on MySQL (CI job "MySQL look-alike ids", `BLOG_KIT_MYSQL_URL`): the user id columns of
 * `user_reference: id` use a binary NO PAD collation, so `bob`, `Bob`, `bób` and `bo´b` never match each other's rows,
 * in SQL already (before `LIMIT`) and again in PHP.
 */
#[Group('mysql')]
final class MySqlLookAlikeIdsTest extends TestCase
{
    private EntityManager $entityManager;

    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('BLOG_KIT_MYSQL_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('BLOG_KIT_MYSQL_URL is not set (MySQL CI job only).');
        }

        LocaleTestSupport::bindDefaults();
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        if (!Type::hasType(BlogUserRefType::NAME)) {
            Type::addType(BlogUserRefType::NAME, BlogUserRefType::class);
        }

        $this->connection = DriverManager::getConnection(new DsnParser(['mysql' => 'pdo_mysql'])->parse($url), $configuration);
        $events           = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new BlogUserReferenceMetadataListener());
        $this->entityManager = new EntityManager($this->connection, $configuration, $events);
        $tool                = new SchemaTool($this->entityManager);
        $metadata            = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    #[Test]
    public function theColumnsCompareByteForByte(): void
    {
        $collation = $this->connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_blog_article' AND COLUMN_NAME = 'created_by_id'");
        self::assertContains($collation, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin']);

        $owners = ['bob' => 'p-1', 'Bob' => 'p-2', "b\u{00F3}b" => 'p-3', "bo\u{0301}b" => 'p-4', 'BOB' => 'p-5'];
        foreach ($owners as $owner => $slug) {
            $article = new BlogArticle()->setSlug($slug)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
            $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle($slug));
            $article->setCreatedBy(new BlogUserRef($owner));
            $this->entityManager->persist($article);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        foreach ($owners as $owner => $slug) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article WHERE created_by_id = ?', [$owner]), $owner);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article WHERE created_by_id = ?', ['bob ']), 'NO PAD: a trailing space is another id');

        $repository = new BlogArticleRepository(new TestManagerRegistry($this->entityManager, $this->connection), LocaleTestSupport::create(), new BlogUsers(BlogUsers::ID));
        foreach ($owners as $owner => $slug) {
            // One row per page: the exact match happens before LIMIT, so a look-alike never hides the owner's row.
            $page  = $repository->findFilteredPaginated([], 1, 1, $owner);
            $slugs = array_map(static fn (BlogArticle $article): string => $article->getSlug(), $page['items']);
            sort($slugs);
            self::assertSame([$slug], $slugs, $owner);
            self::assertSame(1, $page['total'], $owner);
        }
    }
}
