<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\Concerns\JoinsTranslationsAndAuditUsers;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Doctrine repository for blog tags.
 *
 * @extends ServiceEntityRepository<BlogTag>
 */
class BlogTagRepository extends ServiceEntityRepository implements ResetInterface
{
    use JoinsTranslationsAndAuditUsers;

    /** @var array<string, list<array{slug: string, name: string, count: int}>> */
    private array $publishedTagSummariesCache = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogTag::class);
    }

    public function reset(): void
    {
        $this->publishedTagSummariesCache = [];
    }

    public function findBySlug(string $slug): ?BlogTag
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return list<BlogTag> */
    public function findAllOrdered(): array
    {
        $queryBuilder = $this->createQueryBuilder('t')
            ->orderBy('t.slug', 'ASC');

        $this->joinTranslations($queryBuilder, 't', 'tt');

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @param array<string, string> $filters Keys: slug, name
     *
     * @return list<BlogTag>
     */
    public function findFiltered(array $filters): array
    {
        if ($filters === []) {
            return $this->findAllOrdered();
        }

        $queryBuilder = $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tt')->addSelect('tt')
            ->orderBy('t.slug', 'ASC');

        if (($filters['slug'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(t.slug) LIKE :slug')
                ->setParameter('slug', '%' . mb_strtolower($filters['slug']) . '%');
        }

        if (($filters['name'] ?? '') !== '') {
            $queryBuilder
                ->andWhere('LOWER(tt.name) LIKE :name')
                ->setParameter('name', '%' . mb_strtolower($filters['name']) . '%')
                ->distinct();
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Article counts keyed by tag id (single query for admin list).
     *
     * @return array<int, int>
     */
    public function countArticlesByTagId(): array
    {
        /** @var list<array{id: int|string, cnt: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.id AS id', 'COUNT(a.id) AS cnt')
            ->leftJoin('t.articles', 'a')
            ->groupBy('t.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Tags used by at least one published article, with article count.
     *
     * @return list<array{slug: string, name: string, count: int}>
     */
    public function findPublishedTagSummaries(string $locale): array
    {
        if (isset($this->publishedTagSummariesCache[$locale])) {
            return $this->publishedTagSummariesCache[$locale];
        }

        /** @var list<array{slug: string, name: string, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.slug AS slug', 'tt.name AS name', 'COUNT(DISTINCT a.id) AS count')
            ->innerJoin('t.translations', 'tt', 'WITH', 'tt.locale = :locale')
            ->innerJoin('t.articles', 'a')
            ->andWhere('a.published = :published')
            ->setParameter('locale', $locale)
            ->setParameter('published', true)
            ->groupBy('t.id', 't.slug', 'tt.name')
            ->having('COUNT(DISTINCT a.id) > 0')
            ->orderBy('tt.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $this->publishedTagSummariesCache[$locale] = array_map(
            static fn (array $row): array => [
                'slug'  => (string) $row['slug'],
                'name'  => (string) $row['name'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }
}
