<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\Concerns\JoinsTranslationsAndAuditUsers;
use Nowo\BlogKitBundle\Repository\Concerns\RunsDocumentedSql;

use function count;
use function is_string;

/**
 * Doctrine repository for blog articles.
 *
 * @extends ServiceEntityRepository<BlogArticle>
 */
class BlogArticleRepository extends ServiceEntityRepository
{
    use JoinsTranslationsAndAuditUsers;
    use RunsDocumentedSql;

    public function __construct(
        ManagerRegistry $registry,
        private readonly BlogLocales $blogLocales,
    ) {
        parent::__construct($registry, BlogArticle::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPublishedListData(string $locale): array
    {
        $articles      = $this->fetchPublishedArticleRows($locale);
        $tagsByArticle = $this->fetchTagsByArticleIds($locale, array_column($articles, 'id'));

        return $this->mapArticleRows($articles, $tagsByArticle);
    }

    /** @return array<string, mixed>|null */
    public function fetchPublishedDetailBySlug(string $slug, string $locale): ?array
    {
        $fallback = $this->blogLocales->getDefault();

        $sql = <<<'SQL'
            -- Fetches a published blog article by slug with active-locale translation or fallback
            -- Returns one row with slug, metadata, image, dates, and translated content
            SELECT
                b.id,
                b.slug,
                b.image,
                b.published_at,
                b.linkedin_url,
                COALESCE(bt.title, bt_fb.title, '') AS title,
                COALESCE(bt.meta_title, bt_fb.meta_title, '') AS meta_title,
                COALESCE(bt.meta_description, bt_fb.meta_description, '') AS meta_description,
                COALESCE(bt.excerpt, bt_fb.excerpt, '') AS excerpt,
                COALESCE(bt.body, bt_fb.body, '') AS body
            FROM content_blog_article b
            LEFT JOIN content_blog_article_translation bt
                ON bt.translatable_id = b.id AND bt.locale = :locale
            LEFT JOIN content_blog_article_translation bt_fb
                ON bt_fb.translatable_id = b.id AND bt_fb.locale = :fallback
            WHERE b.published = 1 AND b.slug = :slug
            LIMIT 1
            SQL;

        $row = $this->fetchAssociativeDocumentedSql($sql, [
            'slug'     => $slug,
            'locale'   => $locale,
            'fallback' => $fallback,
        ]);

        if ($row === false) {
            return null;
        }

        $articleId     = (int) $row['id'];
        $tagsByArticle = $this->fetchTagsByArticleIds($locale, [$articleId]);
        $mapped        = $this->mapArticleRows([$row], $tagsByArticle)[0];

        $mapped['resources'] = $this->fetchResourcesByArticleIds([$articleId])[$articleId] ?? [];

        return $mapped;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function fetchPublishedPaginatedData(
        int $page,
        int $perPage,
        string $locale,
        ?string $search = null,
        ?string $tagSlug = null,
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        $offset  = ($page - 1) * $perPage;
        $filter  = $this->buildPublishedFilterSql($locale, $search, $tagSlug);

        $countSql = <<<SQL
            -- Counts published blog articles with search and tag filters applied
            -- Returns the total row count matching pagination criteria
            SELECT COUNT(b.id) AS total
            FROM content_blog_article b
            {$filter['joins']}
            WHERE {$filter['where']}
            SQL;

        $total = (int) $this->fetchOneDocumentedSql($countSql, $filter['params']);

        $listSql = <<<SQL
            -- Lists paginated published blog articles with active-locale translation or fallback
            -- Returns rows with id, slug, image, dates, and translated fields ordered by position
            SELECT
                b.id,
                b.slug,
                b.image,
                b.published_at,
                b.linkedin_url,
                COALESCE(bt.title, bt_fb.title, '') AS title,
                COALESCE(bt.meta_title, bt_fb.meta_title, '') AS meta_title,
                COALESCE(bt.meta_description, bt_fb.meta_description, '') AS meta_description,
                COALESCE(bt.excerpt, bt_fb.excerpt, '') AS excerpt,
                COALESCE(bt.body, bt_fb.body, '') AS body
            FROM content_blog_article b
            {$filter['joins']}
            WHERE {$filter['where']}
            ORDER BY b.position ASC, b.published_at DESC, b.slug ASC
            LIMIT {$perPage} OFFSET {$offset}
            SQL;

        $rows          = $this->fetchAllDocumentedSql($listSql, $filter['params']);
        $tagsByArticle = $this->fetchTagsByArticleIds($locale, array_column($rows, 'id'));

        return [
            'items' => $this->mapArticleRows($rows, $tagsByArticle),
            'total' => $total,
        ];
    }

    /**
     * Latest published articles matching the current blog filters (by published_at).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchLatestMatchingFilters(
        string $locale,
        ?string $search = null,
        ?string $tagSlug = null,
        int $limit = 5,
    ): array {
        $limit  = max(1, min(24, $limit));
        $filter = $this->buildPublishedFilterSql($locale, $search, $tagSlug);

        $listSql = <<<SQL
            -- Latest published blog articles matching active search/tag filters
            -- Returns compact rows for the blog index sidebar
            SELECT
                b.id,
                b.slug,
                b.image,
                b.published_at,
                b.linkedin_url,
                COALESCE(bt.title, bt_fb.title, '') AS title,
                COALESCE(bt.meta_title, bt_fb.meta_title, '') AS meta_title,
                COALESCE(bt.meta_description, bt_fb.meta_description, '') AS meta_description,
                COALESCE(bt.excerpt, bt_fb.excerpt, '') AS excerpt,
                COALESCE(bt.body, bt_fb.body, '') AS body
            FROM content_blog_article b
            {$filter['joins']}
            WHERE {$filter['where']}
            ORDER BY b.published_at DESC, b.position ASC, b.slug ASC
            LIMIT {$limit}
            SQL;

        $rows          = $this->fetchAllDocumentedSql($listSql, $filter['params']);
        $tagsByArticle = $this->fetchTagsByArticleIds($locale, array_column($rows, 'id'));

        return $this->mapArticleRows($rows, $tagsByArticle);
    }

    /**
     * Tags present on articles that match the current filters (co-occurring tags).
     *
     * @return list<array{slug: string, name: string, count: int}>
     */
    public function fetchTagSummariesMatchingFilters(
        string $locale,
        ?string $search = null,
        ?string $tagSlug = null,
    ): array {
        $filter   = $this->buildPublishedFilterSql($locale, $search, $tagSlug);
        $fallback = $this->blogLocales->getDefault();

        $sql = <<<SQL
            -- Tag summaries for articles matching the active blog filters (incl. co-occurring tags)
            -- Returns slug, localized name, and matching published-article count ordered by popularity
            SELECT
                t.slug AS slug,
                COALESCE(tt.name, tt_fb.name, t.slug) AS name,
                COUNT(DISTINCT b.id) AS count
            FROM content_blog_article b
            {$filter['joins']}
            INNER JOIN content_blog_article_tag bat ON bat.blog_article_id = b.id
            INNER JOIN content_blog_tag t ON t.id = bat.blog_tag_id
            LEFT JOIN content_blog_tag_translation tt
                ON tt.translatable_id = t.id AND tt.locale = :tagLocale
            LEFT JOIN content_blog_tag_translation tt_fb
                ON tt_fb.translatable_id = t.id AND tt_fb.locale = :tagFallback
            WHERE {$filter['where']}
            GROUP BY t.id, t.slug, tt.name, tt_fb.name
            HAVING COUNT(DISTINCT b.id) > 0
            ORDER BY count DESC, name ASC
            SQL;

        $params                = $filter['params'];
        $params['tagLocale']   = $locale;
        $params['tagFallback'] = $fallback;

        /** @var list<array{slug: string, name: string, count: int|string}> $rows */
        $rows = $this->fetchAllDocumentedSql($sql, $params);

        return array_map(
            static fn (array $row): array => [
                'slug'  => (string) $row['slug'],
                'name'  => (string) $row['name'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    /** @return list<BlogArticle> */
    public function findPublishedOrdered(): array
    {
        return $this->createPublishedQueryBuilder()
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: list<BlogArticle>, total: int}
     */
    public function findPublishedPaginated(
        int $page,
        int $perPage,
        string $locale,
        ?string $search = null,
        ?string $tagSlug = null,
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        $queryBuilder = $this->createPublishedQueryBuilder('b')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($search !== null && trim($search) !== '') {
            $queryBuilder
                ->andWhere('bt.locale = :locale')
                ->andWhere('LOWER(bt.title) LIKE :search OR LOWER(bt.excerpt) LIKE :search OR LOWER(bt.body) LIKE :search')
                ->setParameter('locale', $locale)
                ->setParameter('search', '%' . mb_strtolower(trim($search)) . '%')
                ->distinct();
        }

        if ($tagSlug !== null && trim($tagSlug) !== '') {
            $queryBuilder
                ->innerJoin('b.tags', 'tag')
                ->andWhere('tag.slug = :tagSlug')
                ->setParameter('tagSlug', trim($tagSlug));
        }

        $query     = $queryBuilder->getQuery();
        $paginator = new Paginator($query);

        /** @var list<BlogArticle> $items */
        $items = iterator_to_array($paginator);

        return [
            'items' => $items,
            'total' => count($paginator),
        ];
    }

    /** @return list<BlogArticle> */
    public function findAllOrdered(): array
    {
        $queryBuilder = $this->createQueryBuilder('b')
            ->orderBy('b.position', 'ASC')
            ->addOrderBy('b.publishedAt', 'DESC')
            ->addOrderBy('b.slug', 'ASC');

        $this->joinTranslations($queryBuilder, 'b', 'bt');
        $this->joinAuditUsers($queryBuilder, 'b');

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @param array<string, string> $filters Keys: title, slug, published (1|0)
     *
     * @return array{items: list<BlogArticle>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function findFilteredPaginated(array $filters, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $queryBuilder = $this->createQueryBuilder('b')
            ->leftJoin('b.translations', 'bt')->addSelect('bt')
            ->leftJoin('b.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('b.updatedBy', 'ub')->addSelect('ub')
            ->orderBy('b.position', 'ASC')
            ->addOrderBy('b.publishedAt', 'DESC')
            ->addOrderBy('b.slug', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (($filters['title'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(bt.title) LIKE :title')
                ->setParameter('title', '%' . mb_strtolower($filters['title']) . '%')
                ->distinct();
        }

        if (($filters['slug'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(b.slug) LIKE :slug')
                ->setParameter('slug', '%' . mb_strtolower($filters['slug']) . '%');
        }

        if (($filters['published'] ?? '') === '1') {
            $queryBuilder->andWhere('b.published = true');
        } elseif (($filters['published'] ?? '') === '0') {
            $queryBuilder->andWhere('b.published = false');
        }

        $paginator = new Paginator($queryBuilder->getQuery());
        /** @var list<BlogArticle> $items */
        $items = iterator_to_array($paginator);
        $total = count($paginator);

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @param array<string, string> $filters Keys: title, slug, published (1|0)
     *
     * @return list<BlogArticle>
     */
    public function findFiltered(array $filters): array
    {
        if ($filters === []) {
            return $this->findAllOrdered();
        }

        $queryBuilder = $this->createQueryBuilder('b')
            ->leftJoin('b.translations', 'bt')->addSelect('bt')
            ->leftJoin('b.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('b.updatedBy', 'ub')->addSelect('ub')
            ->orderBy('b.position', 'ASC')
            ->addOrderBy('b.publishedAt', 'DESC')
            ->addOrderBy('b.slug', 'ASC');

        if (($filters['title'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(bt.title) LIKE :title')
                ->setParameter('title', '%' . mb_strtolower($filters['title']) . '%')
                ->distinct();
        }

        if (($filters['slug'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(b.slug) LIKE :slug')
                ->setParameter('slug', '%' . mb_strtolower($filters['slug']) . '%');
        }

        if (($filters['published'] ?? '') === '1') {
            $queryBuilder->andWhere('b.published = true');
        } elseif (($filters['published'] ?? '') === '0') {
            $queryBuilder->andWhere('b.published = false');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function findPublishedBySlug(string $slug): ?BlogArticle
    {
        return $this->createPublishedQueryBuilder('b')
            ->andWhere('b.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Latest published articles that share at least one tag with the given article.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRelatedBySharedTags(int $articleId, string $locale, int $limit = 5): array
    {
        $limit    = max(1, $limit);
        $fallback = $this->blogLocales->getDefault();

        $sql = <<<SQL
            -- Lists published articles sharing at least one tag with the given article
            -- Returns related posts ordered by publication date for the article sidebar
            SELECT
                b.id,
                b.slug,
                b.image,
                b.published_at,
                b.linkedin_url,
                COALESCE(bt.title, bt_fb.title, '') AS title,
                COALESCE(bt.meta_title, bt_fb.meta_title, '') AS meta_title,
                COALESCE(bt.meta_description, bt_fb.meta_description, '') AS meta_description,
                COALESCE(bt.excerpt, bt_fb.excerpt, '') AS excerpt,
                COALESCE(bt.body, bt_fb.body, '') AS body
            FROM content_blog_article b
            INNER JOIN content_blog_article_tag shared
                ON shared.blog_article_id = b.id
            INNER JOIN content_blog_article_tag mine
                ON mine.blog_tag_id = shared.blog_tag_id
                AND mine.blog_article_id = :article_id
            LEFT JOIN content_blog_article_translation bt
                ON bt.translatable_id = b.id AND bt.locale = :locale
            LEFT JOIN content_blog_article_translation bt_fb
                ON bt_fb.translatable_id = b.id AND bt_fb.locale = :fallback
            WHERE b.published = 1
              AND b.id <> :article_id
            GROUP BY b.id, b.slug, b.image, b.published_at, b.linkedin_url, bt.title, bt_fb.title, bt.meta_title, bt_fb.meta_title, bt.meta_description, bt_fb.meta_description, bt.excerpt, bt_fb.excerpt, bt.body, bt_fb.body
            ORDER BY b.published_at DESC, b.position ASC, b.slug ASC
            LIMIT {$limit}
            SQL;

        $rows = $this->fetchAllDocumentedSql($sql, [
            'article_id' => $articleId,
            'locale'     => $locale,
            'fallback'   => $fallback,
        ]);
        $tagsByArticle = $this->fetchTagsByArticleIds($locale, array_column($rows, 'id'));

        return $this->mapArticleRows($rows, $tagsByArticle);
    }

    /**
     * Tags assigned to an article, with global published-article counts.
     *
     * @return list<array{slug: string, name: string, count: int}>
     */
    public function fetchArticleTagSummaries(int $articleId, string $locale): array
    {
        $fallback = $this->blogLocales->getDefault();

        $sql = <<<'SQL'
            -- Summarises tags of one article including how many published posts use each tag
            -- Returns slug, localized name and published usage count
            SELECT
                t.slug,
                COALESCE(tt.name, tt_fb.name, t.slug) AS name,
                COUNT(DISTINCT published.id) AS count
            FROM content_blog_article_tag mine
            INNER JOIN content_blog_tag t
                ON t.id = mine.blog_tag_id
            LEFT JOIN content_blog_tag_translation tt
                ON tt.translatable_id = t.id AND tt.locale = :locale
            LEFT JOIN content_blog_tag_translation tt_fb
                ON tt_fb.translatable_id = t.id AND tt_fb.locale = :fallback
            LEFT JOIN content_blog_article_tag usage_tag
                ON usage_tag.blog_tag_id = t.id
            LEFT JOIN content_blog_article published
                ON published.id = usage_tag.blog_article_id
                AND published.published = 1
            WHERE mine.blog_article_id = :article_id
            GROUP BY t.id, t.slug, tt.name, tt_fb.name
            ORDER BY name ASC, t.slug ASC
            SQL;

        $rows = $this->fetchAllDocumentedSql($sql, [
            'article_id' => $articleId,
            'locale'     => $locale,
            'fallback'   => $fallback,
        ]);

        return array_map(
            static fn (array $row): array => [
                'slug'  => (string) $row['slug'],
                'name'  => (string) $row['name'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * @param list<int|string> $articleIds
     *
     * @return array<int, list<array{id: int, title: string|null, image: string, position: int}>>
     */
    public function fetchResourcesByArticleIds(array $articleIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (int|string $id): int => (int) $id, $articleIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_map(static fn (int $i): string => ':id' . $i, array_keys($ids)));
        $params       = [];

        foreach ($ids as $i => $id) {
            $params['id' . $i] = $id;
        }

        $sql = <<<SQL
            -- Loads sidebar resources for the given blog articles
            -- Returns image/title rows ordered by position
            SELECT
                r.id,
                r.article_id,
                r.title,
                r.image,
                r.position
            FROM content_blog_article_resource r
            WHERE r.article_id IN ({$placeholders})
            ORDER BY r.position ASC, r.id ASC
            SQL;

        $rows    = $this->fetchAllDocumentedSql($sql, $params);
        $grouped = [];

        foreach ($rows as $row) {
            $articleId             = (int) $row['article_id'];
            $grouped[$articleId][] = [
                'id'       => (int) $row['id'],
                'title'    => self::nullableResourceTitle($row['title'] ?? null),
                'image'    => (string) $row['image'],
                'position' => (int) $row['position'],
            ];
        }

        return $grouped;
    }

    private static function nullableResourceTitle(mixed $title): ?string
    {
        if ($title === null || (string) $title === '') {
            return null;
        }

        return (string) $title;
    }

    /**
     * @return array{joins: string, where: string, params: array<string, mixed>}
     */
    private function buildPublishedFilterSql(
        string $locale,
        ?string $search = null,
        ?string $tagSlug = null,
    ): array {
        $fallback = $this->blogLocales->getDefault();
        $search   = $search !== null ? trim($search) : '';
        $tagSlug  = $tagSlug !== null ? trim($tagSlug) : '';

        $conditions = ['b.published = 1'];
        $params     = [
            'locale'   => $locale,
            'fallback' => $fallback,
        ];

        $joins = <<<'SQL'
            LEFT JOIN content_blog_article_translation bt
                ON bt.translatable_id = b.id AND bt.locale = :locale
            LEFT JOIN content_blog_article_translation bt_fb
                ON bt_fb.translatable_id = b.id AND bt_fb.locale = :fallback
            SQL;

        if ($search !== '') {
            $joins = <<<'SQL'
                INNER JOIN content_blog_article_translation bt
                    ON bt.translatable_id = b.id AND bt.locale = :locale
                LEFT JOIN content_blog_article_translation bt_fb
                    ON bt_fb.translatable_id = b.id AND bt_fb.locale = :fallback
                SQL;
            $conditions[]     = '(LOWER(bt.title) LIKE :search OR LOWER(bt.excerpt) LIKE :search OR LOWER(bt.body) LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search) . '%';
        }

        if ($tagSlug !== '') {
            $conditions[] = <<<'SQL'
                EXISTS (
                    SELECT 1
                    FROM content_blog_article_tag bat_filter
                    INNER JOIN content_blog_tag tag_filter ON tag_filter.id = bat_filter.blog_tag_id
                    WHERE bat_filter.blog_article_id = b.id AND tag_filter.slug = :tagSlug
                )
                SQL;
            $params['tagSlug'] = $tagSlug;
        }

        return [
            'joins'  => $joins,
            'where'  => implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    private function createPublishedQueryBuilder(string $alias = 'b'): QueryBuilder
    {
        $translationAlias = $alias . 't';

        return $this->createQueryBuilder($alias)
            ->leftJoin($alias . '.translations', $translationAlias)->addSelect($translationAlias)
            ->andWhere($alias . '.published = :published')
            ->setParameter('published', true)
            ->orderBy($alias . '.position', 'ASC')
            ->addOrderBy($alias . '.publishedAt', 'DESC')
            ->addOrderBy($alias . '.slug', 'ASC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPublishedArticleRows(string $locale): array
    {
        $fallback = $this->blogLocales->getDefault();

        $sql = <<<'SQL'
            -- Lists published blog articles with active-locale translation or fallback
            -- Returns rows with id, slug, image, dates, and translated fields ordered by position
            SELECT
                b.id,
                b.slug,
                b.image,
                b.published_at,
                b.linkedin_url,
                COALESCE(bt.title, bt_fb.title, '') AS title,
                COALESCE(bt.meta_title, bt_fb.meta_title, '') AS meta_title,
                COALESCE(bt.meta_description, bt_fb.meta_description, '') AS meta_description,
                COALESCE(bt.excerpt, bt_fb.excerpt, '') AS excerpt,
                COALESCE(bt.body, bt_fb.body, '') AS body
            FROM content_blog_article b
            LEFT JOIN content_blog_article_translation bt
                ON bt.translatable_id = b.id AND bt.locale = :locale
            LEFT JOIN content_blog_article_translation bt_fb
                ON bt_fb.translatable_id = b.id AND bt_fb.locale = :fallback
            WHERE b.published = 1
            ORDER BY b.position ASC, b.published_at DESC, b.slug ASC
            SQL;

        return $this->fetchAllDocumentedSql($sql, [
            'locale'   => $locale,
            'fallback' => $fallback,
        ]);
    }

    /**
     * @param list<int|string> $articleIds
     *
     * @return array<int, list<array{slug: string, name: string}>>
     */
    private function fetchTagsByArticleIds(string $locale, array $articleIds): array
    {
        $articleIds = array_values(array_filter(array_map(intval(...), $articleIds)));

        if ($articleIds === []) {
            return [];
        }

        $fallback          = $this->blogLocales->getDefault();
        $namedPlaceholders = [];
        $params            = [
            'locale'   => $locale,
            'fallback' => $fallback,
        ];

        foreach ($articleIds as $index => $articleId) {
            $key                 = 'articleId' . $index;
            $namedPlaceholders[] = ':' . $key;
            $params[$key]        = $articleId;
        }

        $inClause = implode(', ', $namedPlaceholders);

        $sql = <<<SQL
            -- Fetches blog tags linked to a list of article ids
            -- Returns rows with article_id, tag slug, and translated name
            SELECT
                bat.blog_article_id AS article_id,
                t.slug,
                COALESCE(tt.name, tt_fb.name, '') AS name
            FROM content_blog_article_tag bat
            INNER JOIN content_blog_tag t ON t.id = bat.blog_tag_id
            LEFT JOIN content_blog_tag_translation tt
                ON tt.translatable_id = t.id AND tt.locale = :locale
            LEFT JOIN content_blog_tag_translation tt_fb
                ON tt_fb.translatable_id = t.id AND tt_fb.locale = :fallback
            WHERE bat.blog_article_id IN ({$inClause})
            ORDER BY t.slug ASC
            SQL;

        $rows = $this->fetchAllDocumentedSql($sql, $params);

        $grouped = [];

        foreach ($rows as $row) {
            $articleId             = (int) $row['article_id'];
            $grouped[$articleId][] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
            ];
        }

        return $grouped;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, list<array{slug: string, name: string}>> $tagsByArticle
     *
     * @return list<array<string, mixed>>
     */
    private function mapArticleRows(array $rows, array $tagsByArticle): array
    {
        return array_values(array_map(
            static function (array $row) use ($tagsByArticle): array {
                $publishedAt = $row['published_at'] ?? null;
                $articleId   = (int) $row['id'];

                return [
                    'id'               => $articleId,
                    'slug'             => (string) $row['slug'],
                    'title'            => (string) $row['title'],
                    'meta_title'       => (string) ($row['meta_title'] ?? ''),
                    'meta_description' => (string) ($row['meta_description'] ?? ''),
                    'excerpt'          => (string) $row['excerpt'],
                    'body'             => (string) ($row['body'] ?? '') !== '' ? (string) $row['body'] : null,
                    'image'            => $row['image'] ?? null,
                    'published_at'     => $publishedAt instanceof DateTimeInterface
                        ? $publishedAt->format('Y-m-d')
                        : (is_string($publishedAt) && $publishedAt !== '' ? substr($publishedAt, 0, 10) : null),
                    'linkedin_url' => $row['linkedin_url'] ?? null,
                    'tags'         => $tagsByArticle[$articleId] ?? [],
                    'resources'    => [],
                ];
            },
            $rows,
        ));
    }
}
