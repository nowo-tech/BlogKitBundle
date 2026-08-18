<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;

/**
 * Doctrine repository for blog article translations.
 *
 * @extends ServiceEntityRepository<BlogArticleTranslation>
 */
final class BlogArticleTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogArticleTranslation::class);
    }
}
