<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleResource;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\TestCase;

use function dirname;
use function is_object;
use function is_string;
use function sprintf;

use const CASE_LOWER;

abstract class DoctrineTestCase extends TestCase
{
    protected EntityManager $entityManager;
    protected Connection $connection;
    protected TestManagerRegistry $registry;

    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();

        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [
                dirname(__DIR__, 2) . '/src/Entity',
                dirname(__DIR__) . '/Support',
            ],
            true,
        );

        $configuration->enableNativeLazyObjects(true);

        // Native SQL and #[ORM\Index] column lists use snake_case.
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);

        $eventManager                = new EventManager();
        $resolveTargetEntityListener = new ResolveTargetEntityListener();
        $resolveTargetEntityListener->addResolveTargetEntity(
            BlogUserInterface::class,
            MappedTestUser::class,
            [],
        );
        $eventManager->addEventSubscriber($resolveTargetEntityListener);

        $this->entityManager = new EntityManager($this->connection, $configuration, $eventManager);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->registry = new TestManagerRegistry($this->entityManager, $this->connection);
    }

    protected function createUser(string $email): MappedTestUser
    {
        $user = (new MappedTestUser())->setEmail($email);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param list<BlogTag> $tags
     * @param list<array{title?: string|null, image: string, position?: int}> $resources
     */
    protected function createArticle(
        string $slug,
        bool $published = true,
        int $position = 0,
        ?DateTimeImmutable $publishedAt = null,
        array $tags = [],
        array $resources = [],
        ?MappedTestUser $createdBy = null,
        ?MappedTestUser $updatedBy = null,
        ?string $image = null,
        ?string $linkedinUrl = null,
        ?string $titleEs = null,
        ?string $titleEn = null,
        ?string $excerptEs = null,
        ?string $bodyEs = null,
        ?string $metaTitleEs = null,
        ?string $metaDescriptionEs = null,
    ): BlogArticle {
        $article = (new BlogArticle())
            ->setSlug($slug)
            ->setPublished($published)
            ->setPosition($position)
            ->setPublishedAt($publishedAt)
            ->setImage($image)
            ->setLinkedinUrl($linkedinUrl);

        if (method_exists($article, 'setCreatedBy')) {
            $article->setCreatedBy($createdBy);
        }

        if (method_exists($article, 'setUpdatedBy')) {
            $article->setUpdatedBy($updatedBy);
        }

        if (method_exists($article, 'setCreatedAt')) {
            $article->setCreatedAt($publishedAt ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        }

        if (method_exists($article, 'setUpdatedAt')) {
            $article->setUpdatedAt($publishedAt ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        }

        $translationEs = (new BlogArticleTranslation())
            ->setLocale('es')
            ->setTitle($titleEs ?? strtoupper(str_replace('-', ' ', $slug)) . ' ES')
            ->setMetaTitle($metaTitleEs ?? 'Meta ' . $slug . ' ES')
            ->setMetaDescription($metaDescriptionEs ?? 'Meta description ' . $slug . ' ES')
            ->setExcerpt($excerptEs ?? 'Excerpt ' . $slug . ' ES')
            ->setBody($bodyEs ?? 'Body ' . $slug . ' ES');
        $article->addTranslation($translationEs);

        if ($titleEn !== null) {
            $translationEn = (new BlogArticleTranslation())
                ->setLocale('en')
                ->setTitle($titleEn)
                ->setMetaTitle('Meta ' . $slug . ' EN')
                ->setMetaDescription('Meta description ' . $slug . ' EN')
                ->setExcerpt('Excerpt ' . $slug . ' EN')
                ->setBody('Body ' . $slug . ' EN');
            $article->addTranslation($translationEn);
        }

        foreach ($tags as $tag) {
            $article->addTag($tag);
        }

        foreach ($resources as $resourceData) {
            $resource = (new BlogArticleResource())
                ->setTitle($resourceData['title'] ?? null)
                ->setImage($resourceData['image'])
                ->setPosition($resourceData['position'] ?? 0);
            $article->addResource($resource);
        }

        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    protected function createTag(
        string $slug,
        ?string $nameEs = null,
        ?string $nameEn = null,
        ?MappedTestUser $createdBy = null,
        ?MappedTestUser $updatedBy = null,
    ): BlogTag {
        $tag = (new BlogTag())->setSlug($slug);

        if (method_exists($tag, 'setCreatedBy')) {
            $tag->setCreatedBy($createdBy);
        }

        if (method_exists($tag, 'setUpdatedBy')) {
            $tag->setUpdatedBy($updatedBy);
        }

        if (method_exists($tag, 'setCreatedAt')) {
            $tag->setCreatedAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        }

        if (method_exists($tag, 'setUpdatedAt')) {
            $tag->setUpdatedAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        }

        $translationEs = (new BlogTagTranslation())
            ->setLocale('es')
            ->setName($nameEs ?? strtoupper(str_replace('-', ' ', $slug)) . ' ES');
        $tag->addTranslation($translationEs);

        if ($nameEn !== null) {
            $translationEn = (new BlogTagTranslation())
                ->setLocale('en')
                ->setName($nameEn);
            $tag->addTranslation($translationEn);
        }

        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    protected function createComment(
        BlogArticle $article,
        string $authorName,
        string $body,
        BlogCommentStatus $status = BlogCommentStatus::Pending,
        ?BlogComment $parent = null,
        ?MappedTestUser $staffAuthor = null,
        ?MappedTestUser $moderatedBy = null,
        ?string $authorEmail = null,
        ?DateTimeImmutable $createdAt = null,
    ): BlogComment {
        $comment = (new BlogComment())
            ->setArticle($article)
            ->setAuthorName($authorName)
            ->setAuthorEmail($authorEmail)
            ->setBody($body)
            ->setStatus($status)
            ->setParent($parent)
            ->setStaffAuthor($staffAuthor)
            ->setModeratedBy($moderatedBy)
            ->setCreatedAt($createdAt ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        if ($parent instanceof BlogComment) {
            $parent->addReply($comment);
        }

        if ($moderatedBy instanceof MappedTestUser) {
            $comment->setModeratedAt(new DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        }

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $comment;
    }

    protected function createSettings(): BlogSettings
    {
        $settings = new BlogSettings();
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return $settings;
    }

    protected function clearEntityManager(): void
    {
        $this->entityManager->clear();
    }

    protected function assertTableHasColumns(string $table, array $expectedColumns): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);
        $names   = [];

        foreach ($columns as $key => $column) {
            if (is_string($key) && $key !== '') {
                $names[] = strtolower($key);
            }
            if (is_object($column) && method_exists($column, 'getName')) {
                $names[] = strtolower((string) $column->getName());
            }
        }

        $names = array_values(array_unique($names));

        foreach ($expectedColumns as $column) {
            self::assertContains(
                strtolower($column),
                $names,
                sprintf('Missing column "%s" on %s. Found: %s', $column, $table, implode(', ', $names)),
            );
        }
    }
}
