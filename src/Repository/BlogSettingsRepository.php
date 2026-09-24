<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @extends ServiceEntityRepository<BlogSettings>
 */
final class BlogSettingsRepository extends ServiceEntityRepository implements ResetInterface
{
    private ?BlogSettings $blogSettings = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogSettings::class);
    }

    public function reset(): void
    {
        $this->blogSettings = null;
    }

    public function getSingleton(): BlogSettings
    {
        $settings = $this->findSingleton();

        if ($settings instanceof BlogSettings) {
            return $settings;
        }

        $settings = new BlogSettings();
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();

        return $this->blogSettings = $settings;
    }

    /**
     * Settings drive comment protection strategies, so the row is refreshed from the database
     * even when the entity is still in a long-lived identity map (worker mode without reset).
     */
    public function findSingleton(): ?BlogSettings
    {
        if ($this->blogSettings instanceof BlogSettings) {
            return $this->blogSettings;
        }

        $settings = $this->createQueryBuilder('s')
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        if ($settings instanceof BlogSettings) {
            $this->blogSettings = $settings;
        }

        return $settings;
    }
}
