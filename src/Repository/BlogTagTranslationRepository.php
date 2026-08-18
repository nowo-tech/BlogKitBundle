<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;

/**
 * Doctrine repository for blog tag translations.
 *
 * @extends ServiceEntityRepository<BlogTagTranslation>
 */
final class BlogTagTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogTagTranslation::class);
    }
}
