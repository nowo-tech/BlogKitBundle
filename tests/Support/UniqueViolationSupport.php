<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Exception;

/**
 * Since 1.10.0: a unique violation of a flush, and the scalar "slug taken?" query the admin runs after it.
 */
trait UniqueViolationSupport
{
    private static function uniqueViolation(): UniqueConstraintViolationException
    {
        $driver = new class('duplicate') extends Exception implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };

        return new UniqueConstraintViolationException($driver, null);
    }

    /**
     * A query builder whose COUNT query returns $count; $andWheres = the expected andWhere() calls.
     */
    private function countQueryBuilder(int $count, int $andWheres): QueryBuilder
    {
        $query = $this->getMockBuilder(Query::class)->disableOriginalConstructor()->onlyMethods(['getSingleScalarResult'])->getMock();
        $query->method('getSingleScalarResult')->willReturn((string) $count);

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)->disableOriginalConstructor()->onlyMethods(['select', 'andWhere', 'setParameter', 'getQuery'])->getMock();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->expects(self::exactly($andWheres))->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        return $queryBuilder;
    }
}
