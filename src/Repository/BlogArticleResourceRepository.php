<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogArticleResource;

/**
 * @extends ServiceEntityRepository<BlogArticleResource>
 */
final class BlogArticleResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogArticleResource::class);
    }
}
