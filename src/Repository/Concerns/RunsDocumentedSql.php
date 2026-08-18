<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository\Concerns;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Helpers for native SQL queries documented with leading comments.
 *
 * Every statement MUST start with two MySQL `--` comments:
 * 1) what the query does
 * 2) what it returns
 */
trait RunsDocumentedSql
{
    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchAllDocumentedSql(string $sql, array $params = []): array
    {
        $this->assertDocumentedSql($sql);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->sqlConnection()->fetchAllAssociative($sql, $params);

        return array_values($rows);
    }

    /** @param array<string, mixed> $params */
    protected function fetchOneDocumentedSql(string $sql, array $params = []): mixed
    {
        $this->assertDocumentedSql($sql);

        return $this->sqlConnection()->fetchOne($sql, $params);
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
        $row = $this->sqlConnection()->fetchAssociative($sql, $params);

        return $row;
    }

    private function assertDocumentedSql(string $sql): void
    {
        if (preg_match('/^\s*--[^\n]+\n\s*--[^\n]+/s', $sql) !== 1) {
            throw new InvalidArgumentException('Native SQL must start with two MySQL -- comments (purpose, then return shape).');
        }
    }

    private function sqlConnection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
