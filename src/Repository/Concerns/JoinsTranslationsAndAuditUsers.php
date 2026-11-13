<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository\Concerns;

use Doctrine\ORM\QueryBuilder;

use function sprintf;

/**
 * Eager-loads translation collections and audit blame users for admin list queries.
 *
 * Prefer inlining {@see QueryBuilder::leftJoin()} in methods that also filter on
 * translation aliases so PHPStan doctrine.dql can see the join.
 */
trait JoinsTranslationsAndAuditUsers
{
    protected function joinTranslations(QueryBuilder $queryBuilder, string $rootAlias, string $translationAlias = 'tr'): QueryBuilder
    {
        return $queryBuilder
            ->leftJoin(sprintf('%s.translations', $rootAlias), $translationAlias)
            ->addSelect($translationAlias);
    }

    /**
     * @param bool $join since 1.9.0: false with `doctrine.user_reference: id` (the user fields are ids, nothing to join)
     */
    protected function joinAuditUsers(QueryBuilder $queryBuilder, string $rootAlias, bool $join = true): QueryBuilder
    {
        if (!$join) {
            return $queryBuilder;
        }

        return $queryBuilder
            ->leftJoin(sprintf('%s.createdBy', $rootAlias), 'cb')
            ->addSelect('cb')
            ->leftJoin(sprintf('%s.updatedBy', $rootAlias), 'ub')
            ->addSelect('ub');
    }
}
