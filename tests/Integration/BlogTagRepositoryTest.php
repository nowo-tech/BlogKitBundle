<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use PHPUnit\Framework\Attributes\Test;

final class BlogTagRepositoryTest extends DoctrineTestCase
{
    private BlogTagRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BlogTagRepository($this->registry);
    }

    #[Test]
    public function findBySlugReturnsMatchingTagOrNull(): void
    {
        $tag = $this->createTag('php', 'PHP ES');

        self::assertSame($tag->getId(), $this->repository->findBySlug('php')?->getId());
        self::assertNull($this->repository->findBySlug('missing'));
    }

    #[Test]
    public function findAllOrderedReturnsTagsSortedBySlug(): void
    {
        $first  = $this->createTag('doctrine', 'Doctrine ES');
        $second = $this->createTag('symfony', 'Symfony ES');

        $items = $this->repository->findAllOrdered();

        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn (BlogTag $tag): ?int => $tag->getId(), $items),
        );
    }

    #[Test]
    public function findFilteredReturnsAllOrderedRowsForEmptyFilters(): void
    {
        $first  = $this->createTag('api', 'API ES');
        $second = $this->createTag('backend', 'Backend ES');

        $items = $this->repository->findFiltered([]);

        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn (BlogTag $tag): ?int => $tag->getId(), $items),
        );
    }

    #[Test]
    public function findFilteredCanFilterBySlugOrName(): void
    {
        $slugMatch = $this->createTag('performance', 'Performance ES');
        $nameMatch = $this->createTag('symfony', 'Framework Symfony');
        $this->createTag('laravel', 'Laravel');

        $bySlug = $this->repository->findFiltered(['slug' => 'perform']);
        $byName = $this->repository->findFiltered(['name' => 'symfony']);

        self::assertSame([$slugMatch->getId()], array_map(static fn (BlogTag $tag): ?int => $tag->getId(), $bySlug));
        self::assertSame([$nameMatch->getId()], array_map(static fn (BlogTag $tag): ?int => $tag->getId(), $byName));
    }

    #[Test]
    public function countArticlesByTagIdReturnsArticleTotalsPerTag(): void
    {
        $tagPhp     = $this->createTag('php', 'PHP ES');
        $tagSymfony = $this->createTag('symfony', 'Symfony ES');
        $tagEmpty   = $this->createTag('empty', 'Empty ES');

        $this->createArticle(
            slug: 'tagged-one',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            tags: [$tagPhp, $tagSymfony],
        );
        $this->createArticle(
            slug: 'tagged-two',
            published: false,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
            tags: [$tagPhp],
        );

        $counts = $this->repository->countArticlesByTagId();

        self::assertSame(2, $counts[(int) $tagPhp->getId()]);
        self::assertSame(1, $counts[(int) $tagSymfony->getId()]);
        self::assertSame(0, $counts[(int) $tagEmpty->getId()]);
    }

    #[Test]
    public function findPublishedTagSummariesReturnsLocalizedCountsForPublishedArticlesOnly(): void
    {
        $tagPhp     = $this->createTag('php', 'PHP ES');
        $tagSymfony = $this->createTag('symfony', 'Symfony ES');
        $this->createTag('unused', 'Unused ES');

        $this->createArticle(
            slug: 'published-one',
            published: true,
            position: 1,
            publishedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            tags: [$tagPhp, $tagSymfony],
        );
        $this->createArticle(
            slug: 'published-two',
            published: true,
            position: 2,
            publishedAt: new DateTimeImmutable('2026-02-02T00:00:00+00:00'),
            tags: [$tagPhp],
        );
        $this->createArticle(
            slug: 'draft-one',
            published: false,
            position: 3,
            publishedAt: new DateTimeImmutable('2026-02-03T00:00:00+00:00'),
            tags: [$tagSymfony],
        );

        $summaries = $this->repository->findPublishedTagSummaries('es');

        self::assertSame([
            ['slug' => 'php', 'name' => 'PHP ES', 'count' => 2],
            ['slug' => 'symfony', 'name' => 'Symfony ES', 'count' => 1],
        ], $summaries);
    }
}
