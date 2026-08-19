<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;

use function sprintf;

final class BlogArticleRepositoryTest extends DoctrineTestCase
{
    private BlogArticleRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BlogArticleRepository($this->registry, LocaleTestSupport::create());
    }

    #[Test]
    public function fetchPublishedListDataReturnsEmptyArrayWhenRepositoryIsEmpty(): void
    {
        self::assertSame([], $this->repository->fetchPublishedListData('es'));
    }

    #[Test]
    public function fetchPublishedListDataReturnsPublishedRowsWithFallbackTranslationsAndTags(): void
    {
        $tagSymfony = $this->createTag('symfony', 'Symfony ES');
        $tagPhp     = $this->createTag('php', 'PHP ES');
        $this->createArticle(
            slug: 'draft-post',
            published: false,
            position: 0,
            publishedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $this->createArticle(
            slug: 'published-post',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-02-10T00:00:00+00:00'),
            tags: [$tagSymfony, $tagPhp],
            image: '/images/post.png',
            linkedinUrl: 'https://linkedin.example/post',
            titleEs: 'Titulo publicado',
            excerptEs: 'Extracto publicado',
            bodyEs: 'Contenido publicado',
            metaTitleEs: 'Meta publicado',
            metaDescriptionEs: 'Descripcion publicada',
        );

        $rows = $this->repository->fetchPublishedListData('en');

        self::assertCount(1, $rows);
        self::assertSame([
            'id'               => $rows[0]['id'],
            'slug'             => 'published-post',
            'title'            => 'Titulo publicado',
            'meta_title'       => 'Meta publicado',
            'meta_description' => 'Descripcion publicada',
            'excerpt'          => 'Extracto publicado',
            'body'             => 'Contenido publicado',
            'image'            => '/images/post.png',
            'published_at'     => '2026-02-10',
            'linkedin_url'     => 'https://linkedin.example/post',
            'tags'             => [
                ['slug' => 'php', 'name' => 'PHP ES'],
                ['slug' => 'symfony', 'name' => 'Symfony ES'],
            ],
            'resources' => [],
        ], $rows[0]);
    }

    #[Test]
    public function fetchPublishedListDataReusesMemoizedTagRowsOnSubsequentCalls(): void
    {
        $tag = $this->createTag('php', 'PHP ES');
        $this->createArticle(
            slug: 'published-post',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-02-10T00:00:00+00:00'),
            tags: [$tag],
            titleEs: 'Titulo publicado',
        );

        $first  = $this->repository->fetchPublishedListData('es');
        $second = $this->repository->fetchPublishedListData('es');

        self::assertSame($first, $second);

        $this->repository->reset();

        self::assertSame($first, $this->repository->fetchPublishedListData('es'));
    }

    #[Test]
    public function fetchPublishedPaginatedDataReusesPartialTagCacheAcrossRequests(): void
    {
        $tag = $this->createTag('php', 'PHP ES');
        $this->createArticle(
            slug: 'page-one',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
            tags: [$tag],
            titleEs: 'Page one',
        );
        $this->createArticle(
            slug: 'page-two',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-05-02T00:00:00+00:00'),
            tags: [$tag],
            titleEs: 'Page two',
        );

        $firstPage = $this->repository->fetchPublishedPaginatedData(1, 1, 'es');
        self::assertCount(1, $firstPage['items']);

        $bothItems = $this->repository->fetchPublishedPaginatedData(1, 2, 'es');
        self::assertCount(2, $bothItems['items']);

        $cachedFirstPage = $this->repository->fetchPublishedPaginatedData(1, 1, 'es');
        self::assertSame($firstPage['items'], $cachedFirstPage['items']);
    }

    #[Test]
    public function fetchPublishedListDataReturnsEmptyTagsForArticlesWithoutTags(): void
    {
        $this->createArticle(
            slug: 'untagged-post',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            titleEs: 'Sin tags',
        );

        $rows = $this->repository->fetchPublishedListData('es');

        self::assertCount(1, $rows);
        self::assertSame([], $rows[0]['tags']);
    }

    #[Test]
    public function fetchPublishedDetailBySlugReturnsNullWhenSlugIsMissing(): void
    {
        self::assertNull($this->repository->fetchPublishedDetailBySlug('missing-slug', 'es'));
    }

    #[Test]
    public function fetchPublishedDetailBySlugReturnsMappedArticleWithResources(): void
    {
        $tag     = $this->createTag('php', 'PHP ES');
        $article = $this->createArticle(
            slug: 'deep-dive',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-03-15T10:00:00+00:00'),
            tags: [$tag],
            resources: [
                ['title' => 'Slides', 'image' => '/slides.png', 'position' => 2],
                ['title' => '', 'image' => '/cover.png', 'position' => 1],
            ],
            image: '/hero.png',
            linkedinUrl: 'https://linkedin.example/deep-dive',
            titleEs: 'Analisis profundo',
            excerptEs: 'Resumen profundo',
            bodyEs: 'Cuerpo detallado',
            metaTitleEs: 'Meta profunda',
            metaDescriptionEs: 'Descripcion profunda',
        );

        $detail = $this->repository->fetchPublishedDetailBySlug('deep-dive', 'en');

        self::assertNotNull($detail);
        self::assertSame($article->getId(), $detail['id']);
        self::assertSame('Analisis profundo', $detail['title']);
        self::assertSame([
            ['slug' => 'php', 'name' => 'PHP ES'],
        ], $detail['tags']);
        self::assertSame([
            ['id' => $detail['resources'][0]['id'], 'title' => null, 'image' => '/cover.png', 'position' => 1],
            ['id' => $detail['resources'][1]['id'], 'title' => 'Slides', 'image' => '/slides.png', 'position' => 2],
        ], $detail['resources']);
    }

    #[Test]
    public function fetchPublishedPaginatedDataAppliesSearchTagAndPagination(): void
    {
        $tagPhp     = $this->createTag('php', 'PHP ES');
        $tagSymfony = $this->createTag('symfony', 'Symfony ES');

        $first = $this->createArticle(
            slug: 'filters-first',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-04-10T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'API filters first',
            excerptEs: 'Searchable excerpt',
            bodyEs: 'Useful body',
        );
        $second = $this->createArticle(
            slug: 'filters-second',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-04-11T00:00:00+00:00'),
            tags: [$tagPhp, $tagSymfony],
            titleEs: 'API filters second',
            excerptEs: 'Another searchable excerpt',
            bodyEs: 'Useful body',
        );
        $this->createArticle(
            slug: 'other-tag',
            published: true,
            position: 3,
            publishedAt: new DateTimeImmutable('2026-04-12T00:00:00+00:00'),
            tags: [$tagSymfony],
            titleEs: 'API filters other',
            excerptEs: 'Searchable excerpt',
            bodyEs: 'Useful body',
        );
        $this->createArticle(
            slug: 'draft-match',
            published: false,
            position: 0,
            publishedAt: new DateTimeImmutable('2026-04-13T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'API filters draft',
            excerptEs: 'Searchable excerpt',
            bodyEs: 'Useful body',
        );

        $pageOne = $this->repository->fetchPublishedPaginatedData(1, 1, 'es', 'API filters', 'php');
        $pageTwo = $this->repository->fetchPublishedPaginatedData(2, 1, 'es', 'API filters', 'php');

        self::assertSame(2, $pageOne['total']);
        self::assertCount(1, $pageOne['items']);
        self::assertSame($first->getId(), $pageOne['items'][0]['id']);
        self::assertSame(2, $pageTwo['total']);
        self::assertCount(1, $pageTwo['items']);
        self::assertSame($second->getId(), $pageTwo['items'][0]['id']);
    }

    #[Test]
    public function fetchLatestMatchingFiltersClampsRequestedLimit(): void
    {
        $tag = $this->createTag('php', 'PHP ES');

        for ($i = 1; $i <= 26; ++$i) {
            $this->createArticle(
                slug: 'latest-' . $i,
                published: true,
                position: 0,
                publishedAt: new DateTimeImmutable(sprintf('2026-05-%02dT00:00:00+00:00', $i)),
                tags: [$tag],
                titleEs: 'Latest PHP ' . $i,
                excerptEs: 'Sidebar excerpt',
                bodyEs: 'Sidebar body',
            );
        }

        $rows = $this->repository->fetchLatestMatchingFilters('es', 'Latest PHP', 'php', 99);

        self::assertCount(24, $rows);
        self::assertSame('latest-26', $rows[0]['slug']);
        self::assertSame('latest-3', $rows[23]['slug']);
    }

    #[Test]
    public function fetchTagSummariesMatchingFiltersReturnsCoOccurringPublishedTagCounts(): void
    {
        $tagPhp      = $this->createTag('php', 'PHP ES');
        $tagSymfony  = $this->createTag('symfony', 'Symfony ES');
        $tagDoctrine = $this->createTag('doctrine', 'Doctrine ES');

        $this->createArticle(
            slug: 'first-match',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            tags: [$tagPhp, $tagSymfony],
            titleEs: 'Filters one',
            excerptEs: 'Excerpt one',
            bodyEs: 'Common keyword',
        );
        $this->createArticle(
            slug: 'second-match',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-06-02T00:00:00+00:00'),
            tags: [$tagPhp, $tagDoctrine],
            titleEs: 'Filters two',
            excerptEs: 'Excerpt two',
            bodyEs: 'Common keyword',
        );
        $this->createArticle(
            slug: 'unmatched',
            published: true,
            position: 3,
            publishedAt: new DateTimeImmutable('2026-06-03T00:00:00+00:00'),
            tags: [$tagSymfony],
            titleEs: 'Other article',
            excerptEs: 'Other excerpt',
            bodyEs: 'Different content',
        );

        $summaries = $this->repository->fetchTagSummariesMatchingFilters('es', 'Common keyword', 'php');

        self::assertSame([
            ['slug' => 'php', 'name' => 'PHP ES', 'count' => 2],
            ['slug' => 'doctrine', 'name' => 'Doctrine ES', 'count' => 1],
            ['slug' => 'symfony', 'name' => 'Symfony ES', 'count' => 1],
        ], $summaries);
    }

    #[Test]
    public function findPublishedOrderedReturnsOnlyPublishedArticlesInRepositoryOrder(): void
    {
        $expectedFirst = $this->createArticle(
            slug: 'first-published',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-07-01T00:00:00+00:00'),
        );
        $expectedSecond = $this->createArticle(
            slug: 'second-published',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-07-02T00:00:00+00:00'),
        );
        $this->createArticle(
            slug: 'draft-post',
            published: false,
            position: 0,
            publishedAt: new DateTimeImmutable('2026-07-03T00:00:00+00:00'),
        );

        $items = $this->repository->findPublishedOrdered();

        self::assertSame([
            $expectedSecond->getId(),
            $expectedFirst->getId(),
        ], array_map(static fn (BlogArticle $article): ?int => $article->getId(), $items));
    }

    #[Test]
    public function findPublishedPaginatedAppliesSearchTagAndPagination(): void
    {
        $tagPhp     = $this->createTag('php', 'PHP ES');
        $tagSymfony = $this->createTag('symfony', 'Symfony ES');

        $first = $this->createArticle(
            slug: 'orm-first',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'Doctrine ORM first',
            excerptEs: 'Filtering excerpt',
            bodyEs: 'Filtering body',
        );
        $second = $this->createArticle(
            slug: 'orm-second',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-08-02T00:00:00+00:00'),
            tags: [$tagPhp, $tagSymfony],
            titleEs: 'Doctrine ORM second',
            excerptEs: 'Filtering excerpt',
            bodyEs: 'Filtering body',
        );
        $this->createArticle(
            slug: 'other-search',
            published: true,
            position: 3,
            publishedAt: new DateTimeImmutable('2026-08-03T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'Laravel article',
            excerptEs: 'Filtering excerpt',
            bodyEs: 'Filtering body',
        );

        $pageOne = $this->repository->findPublishedPaginated(1, 1, 'es', 'Doctrine ORM', 'php');
        $pageTwo = $this->repository->findPublishedPaginated(2, 1, 'es', 'Doctrine ORM', 'php');

        self::assertSame(2, $pageOne['total']);
        self::assertSame([$first->getId()], array_map(static fn (BlogArticle $article): ?int => $article->getId(), $pageOne['items']));
        self::assertSame(2, $pageTwo['total']);
        self::assertSame([$second->getId()], array_map(static fn (BlogArticle $article): ?int => $article->getId(), $pageTwo['items']));
    }

    #[Test]
    public function findAllOrderedReturnsArticlesWithTranslationsAndAuditColumnsMapped(): void
    {
        $creator = $this->createUser('creator@example.test');
        $updater = $this->createUser('updater@example.test');
        $first   = $this->createArticle(
            slug: 'joined-first',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            createdBy: $creator,
            updatedBy: $updater,
            titleEs: 'Joined first',
        );
        $second = $this->createArticle(
            slug: 'joined-second',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-09-02T00:00:00+00:00'),
            createdBy: $creator,
            updatedBy: $updater,
            titleEs: 'Joined second',
        );

        $items = $this->repository->findAllOrdered();

        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn (BlogArticle $article): ?int => $article->getId(), $items),
        );
        self::assertSame('creator@example.test', $items[0]->getCreatedBy()?->getUserIdentifier());
        self::assertSame('updater@example.test', $items[0]->getUpdatedBy()?->getUserIdentifier());
        $this->assertTableHasColumns('content_blog_article', ['created_at', 'updated_at', 'created_by_id', 'updated_by_id']);
    }

    #[Test]
    public function findFilteredPaginatedReturnsAllOrderedRowsForEmptyFilters(): void
    {
        $first = $this->createArticle(
            slug: 'empty-first',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
        );
        $second = $this->createArticle(
            slug: 'empty-second',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-10-02T00:00:00+00:00'),
        );

        $result = $this->repository->findFilteredPaginated([], 1, 10);

        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn (BlogArticle $article): ?int => $article->getId(), $result['items']),
        );
        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(10, $result['per_page']);
        self::assertSame(1, $result['total_pages']);
    }

    #[Test]
    public function findFilteredPaginatedAppliesTitleSlugAndPublishedFilters(): void
    {
        $published = $this->createArticle(
            slug: 'matching-slug',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-10-10T00:00:00+00:00'),
            titleEs: 'Matching title',
        );
        $draft = $this->createArticle(
            slug: 'matching-draft',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-10-11T00:00:00+00:00'),
            titleEs: 'Matching title',
        );
        $this->createArticle(
            slug: 'other-slug',
            published: true,
            position: 3,
            publishedAt: new DateTimeImmutable('2026-10-12T00:00:00+00:00'),
            titleEs: 'Other title',
        );

        $publishedResult = $this->repository->findFilteredPaginated([
            'title'     => 'matching',
            'slug'      => 'slug',
            'published' => '1',
        ], 1, 10);
        $draftResult = $this->repository->findFilteredPaginated([
            'title'     => 'matching',
            'published' => '0',
        ], 1, 10);

        self::assertSame([$published->getId()], array_map(static fn (BlogArticle $article): ?int => $article->getId(), $publishedResult['items']));
        self::assertSame(1, $publishedResult['total']);
        self::assertSame([$draft->getId()], array_map(static fn (BlogArticle $article): ?int => $article->getId(), $draftResult['items']));
        self::assertSame(1, $draftResult['total']);
    }

    #[Test]
    public function findFilteredReturnsAllOrderedRowsWhenFiltersAreEmpty(): void
    {
        $first = $this->createArticle(
            slug: 'all-first',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        );
        $second = $this->createArticle(
            slug: 'all-second',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-11-02T00:00:00+00:00'),
        );

        $items = $this->repository->findFiltered([]);

        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn (BlogArticle $article): ?int => $article->getId(), $items),
        );
    }

    #[Test]
    public function findFilteredAppliesSupportedFilters(): void
    {
        $published = $this->createArticle(
            slug: 'searched-slug',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-11-10T00:00:00+00:00'),
            titleEs: 'Searchable title',
        );
        $draft = $this->createArticle(
            slug: 'searched-draft',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-11-11T00:00:00+00:00'),
            titleEs: 'Searchable title',
        );

        $publishedItems = $this->repository->findFiltered([
            'title'     => 'searchable',
            'slug'      => 'slug',
            'published' => '1',
        ]);
        $draftItems = $this->repository->findFiltered([
            'title'     => 'searchable',
            'published' => '0',
        ]);

        self::assertSame([$published->getId()], array_map(static fn (BlogArticle $article): ?int => $article->getId(), $publishedItems));
        self::assertSame([$draft->getId()], array_map(static fn (BlogArticle $article): ?int => $article->getId(), $draftItems));
    }

    #[Test]
    public function findFilteredPaginatedRestrictsToCreatedByIncludingUnowned(): void
    {
        $owner = $this->createUser('owner-list@example.test');
        $other = $this->createUser('other-list@example.test');
        $mine  = $this->createArticle(
            slug: 'owned-post',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-11-20T00:00:00+00:00'),
            createdBy: $owner,
        );
        $this->createArticle(
            slug: 'foreign-post',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-11-21T00:00:00+00:00'),
            createdBy: $other,
        );
        $legacy = $this->createArticle(
            slug: 'legacy-post',
            published: true,
            position: 3,
            publishedAt: new DateTimeImmutable('2026-11-22T00:00:00+00:00'),
        );

        $result         = $this->repository->findFilteredPaginated([], 1, 10, (int) $owner->getId());
        $empty          = $this->repository->findFilteredPaginated([], 1, 10, '');
        $items          = $this->repository->findFiltered([], (int) $owner->getId());
        $publishedOwned = $this->repository->findFiltered(['published' => '1'], (int) $owner->getId());

        self::assertSame(
            [$mine->getId(), $legacy->getId()],
            array_map(static fn (BlogArticle $article): ?int => $article->getId(), $result['items']),
        );
        self::assertSame(2, $result['total']);
        self::assertSame([], $empty['items']);
        self::assertSame(0, $empty['total']);
        self::assertSame([], $this->repository->findFiltered([], ''));
        self::assertSame(
            [$mine->getId(), $legacy->getId()],
            array_map(static fn (BlogArticle $article): ?int => $article->getId(), $items),
        );
        self::assertSame(
            [$mine->getId(), $legacy->getId()],
            array_map(static fn (BlogArticle $article): ?int => $article->getId(), $publishedOwned),
        );
    }

    #[Test]
    public function findPublishedBySlugReturnsOnlyPublishedArticles(): void
    {
        $published = $this->createArticle(
            slug: 'visible-post',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-12-01T00:00:00+00:00'),
        );
        $this->createArticle(
            slug: 'hidden-post',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-12-02T00:00:00+00:00'),
        );

        self::assertSame($published->getId(), $this->repository->findPublishedBySlug('visible-post')?->getId());
        self::assertNull($this->repository->findPublishedBySlug('hidden-post'));
        self::assertNull($this->repository->findPublishedBySlug('missing-post'));
    }

    #[Test]
    public function countAllCountsPublishedAndDraftArticles(): void
    {
        $this->createArticle(
            slug: 'count-one',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );
        $this->createArticle(
            slug: 'count-two',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2027-01-02T00:00:00+00:00'),
        );

        self::assertSame(2, $this->repository->countAll());
    }

    #[Test]
    public function fetchRelatedBySharedTagsReturnsPublishedArticlesSharingTagsOnly(): void
    {
        $tagPhp     = $this->createTag('php', 'PHP ES');
        $tagSymfony = $this->createTag('symfony', 'Symfony ES');

        $source = $this->createArticle(
            slug: 'source',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2027-02-01T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'Source',
        );
        $newestRelated = $this->createArticle(
            slug: 'related-newest',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2027-02-03T00:00:00+00:00'),
            tags: [$tagPhp, $tagSymfony],
            titleEs: 'Newest related',
        );
        $olderRelated = $this->createArticle(
            slug: 'related-older',
            published: true,
            position: 3,
            publishedAt: new DateTimeImmutable('2027-02-02T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'Older related',
        );
        $this->createArticle(
            slug: 'draft-related',
            published: false,
            position: 4,
            publishedAt: new DateTimeImmutable('2027-02-04T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'Draft related',
        );
        $this->createArticle(
            slug: 'unrelated',
            published: true,
            position: 5,
            publishedAt: new DateTimeImmutable('2027-02-05T00:00:00+00:00'),
            tags: [$tagSymfony],
            titleEs: 'Unrelated',
        );

        $rows = $this->repository->fetchRelatedBySharedTags((int) $source->getId(), 'es', 10);

        self::assertSame(
            [$newestRelated->getId(), $olderRelated->getId()],
            array_column($rows, 'id'),
        );
    }

    #[Test]
    public function fetchArticleTagSummariesReturnsPublishedUsageCountsForArticleTags(): void
    {
        $tagPhp     = $this->createTag('php', 'PHP ES');
        $tagSymfony = $this->createTag('symfony', 'Symfony ES');

        $article = $this->createArticle(
            slug: 'tagged-source',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2027-03-01T00:00:00+00:00'),
            tags: [$tagPhp, $tagSymfony],
            titleEs: 'Tagged source',
        );
        $this->createArticle(
            slug: 'another-published',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2027-03-02T00:00:00+00:00'),
            tags: [$tagPhp],
            titleEs: 'Another published',
        );
        $this->createArticle(
            slug: 'draft-tagged',
            published: false,
            position: 3,
            publishedAt: new DateTimeImmutable('2027-03-03T00:00:00+00:00'),
            tags: [$tagSymfony],
            titleEs: 'Draft tagged',
        );

        $summaries = $this->repository->fetchArticleTagSummaries((int) $article->getId(), 'es');

        self::assertSame([
            ['slug' => 'php', 'name' => 'PHP ES', 'count' => 2],
            ['slug' => 'symfony', 'name' => 'Symfony ES', 'count' => 1],
        ], $summaries);
    }

    #[Test]
    public function fetchResourcesByArticleIdsReturnsEmptyArrayForNoIdentifiers(): void
    {
        self::assertSame([], $this->repository->fetchResourcesByArticleIds([]));
    }

    #[Test]
    public function undocumentedSqlPathsRejectQueriesWithoutLeadingDocumentationComments(): void
    {
        $repository = new class($this->registry, LocaleTestSupport::create()) extends BlogArticleRepository {
            public function executeInvalidFetchAll(): array
            {
                return $this->fetchAllDocumentedSql('SELECT 1');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native SQL must start with two MySQL -- comments');

        $repository->executeInvalidFetchAll();
    }
}
