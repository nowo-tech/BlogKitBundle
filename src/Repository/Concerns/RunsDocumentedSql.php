<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository\Concerns;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use InvalidArgumentException;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleResource;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;

use function array_keys;
use function implode;
use function strlen;
use function usort;

/**
 * Helpers for native SQL queries documented with leading comments.
 *
 * Every statement MUST start with two MySQL `--` comments:
 * 1) what the query does
 * 2) what it returns
 *
 * Statements are written with the logical table names (`content_blog_*`); they are replaced by the mapped names of
 * the entity metadata before execution, so `doctrine.table_prefix` applies to native SQL too, join table included
 * (SEC-004 L6).
 */
trait RunsDocumentedSql
{
    /** Logical table name => [entity class, many-to-many association of the join table or null]. */
    private const array BLOG_TABLES = [
        'content_blog_article'             => [BlogArticle::class, null],
        'content_blog_article_tag'         => [BlogArticle::class, 'tags'],
        'content_blog_article_translation' => [BlogArticleTranslation::class, null],
        'content_blog_article_resource'    => [BlogArticleResource::class, null],
        'content_blog_tag'                 => [BlogTag::class, null],
        'content_blog_tag_translation'     => [BlogTagTranslation::class, null],
        'content_blog_comment'             => [BlogComment::class, null],
        'content_blog_settings'            => [BlogSettings::class, null],
    ];

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchAllDocumentedSql(string $sql, array $params = []): array
    {
        $this->assertDocumentedSql($sql);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->sqlConnection()->fetchAllAssociative($this->mappedTableSql($sql), $params);

        return array_values($rows);
    }

    /** @param array<string, mixed> $params */
    protected function fetchOneDocumentedSql(string $sql, array $params = []): mixed
    {
        $this->assertDocumentedSql($sql);

        return $this->sqlConnection()->fetchOne($this->mappedTableSql($sql), $params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|false
     */
    protected function fetchAssociativeDocumentedSql(string $sql, array $params = []): array|false
    {
        $this->assertDocumentedSql($sql);

        /** @var array<string, mixed>|false $row */
        $row = $this->sqlConnection()->fetchAssociative($this->mappedTableSql($sql), $params);

        return $row;
    }

    private function assertDocumentedSql(string $sql): void
    {
        if (preg_match('/^\s*--[^\n]+\n\s*--[^\n]+/s', $sql) !== 1) {
            throw new InvalidArgumentException('Native SQL must start with two MySQL -- comments (purpose, then return shape).');
        }
    }

    /**
     * Replaces the logical `content_blog_*` table names with the mapped (prefixed) ones.
     */
    private function mappedTableSql(string $sql): string
    {
        $entityManager = $this->getEntityManager();

        $names = array_keys(self::BLOG_TABLES);
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return preg_replace_callback(
            '/\b(' . implode('|', $names) . ')\b/',
            static function (array $match) use ($entityManager): string {
                [$class, $association] = self::BLOG_TABLES[$match[1]];
                $metadata              = $entityManager->getClassMetadata($class);
                if ($association === null) {
                    return $metadata->getTableName();
                }

                /** @var ManyToManyOwningSideMapping $mapping */
                $mapping = $metadata->getAssociationMapping($association);

                return $mapping->joinTable->name;
            },
            $sql,
        ) ?? $sql;
    }

    private function sqlConnection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
