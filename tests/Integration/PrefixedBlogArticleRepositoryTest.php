<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

use function sort;

/**
 * The whole repository suite on a `doctrine.table_prefix` schema: every native SQL statement must hit the prefixed
 * tables, join table included (SEC-004 L6 — they were hard-coded to content_blog_*).
 */
final class PrefixedBlogArticleRepositoryTest extends BlogArticleRepositoryTestCase
{
    #[Test]
    public function everyBundleTableIsPrefixedJoinTableIncluded(): void
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%content_blog%'");
        sort($tables);

        self::assertSame([
            'tenant_a_content_blog_article',
            'tenant_a_content_blog_article_resource',
            'tenant_a_content_blog_article_tag',
            'tenant_a_content_blog_article_translation',
            'tenant_a_content_blog_comment',
            'tenant_a_content_blog_settings',
            'tenant_a_content_blog_tag',
            'tenant_a_content_blog_tag_translation',
        ], $tables);
    }

    protected function tablePrefix(): string
    {
        return 'tenant_a_';
    }
}
