<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Repository;

use BackedEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use SortDirection;
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

    /**
     * Returns the settings row, creating it with the default values when the table is empty.
     *
     * The row is created with a single DBAL INSERT on the connection of the entity manager that
     * manages BlogSettings, never with a flush: rendering a page must not commit unrelated pending
     * changes of the host's unit of work. Inside an open host transaction the INSERT joins it.
     */
    public function getSingleton(): BlogSettings
    {
        $settings = $this->findSingleton();

        if ($settings instanceof BlogSettings) {
            return $settings;
        }

        $this->insertDefaultRow();

        return $this->findSingleton() ?? throw new LogicException('The blog settings row could not be created.');
    }

    /**
     * Inserts one row with the BlogSettings defaults, bypassing the unit of work (no flush).
     */
    private function insertDefaultRow(): void
    {
        $entityManager = $this->getEntityManager();
        $connection    = $entityManager->getConnection();
        $platform      = $connection->getDatabasePlatform();
        $quoteStrategy = $entityManager->getConfiguration()->getQuoteStrategy();
        $metadata      = $entityManager->getClassMetadata(BlogSettings::class);
        $defaults      = new BlogSettings();

        $data  = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field)) {
                continue;
            }

            $column = $quoteStrategy->getColumnName($field, $metadata, $platform);
            $value  = $metadata->getFieldValue($defaults, $field);

            $data[$column]  = $value instanceof BackedEnum ? $value->value : $value;
            $types[$column] = (string) $metadata->getTypeOfField($field);
        }

        // Sequence-style generators (pre-insert) need the id in the INSERT; identity columns fill it themselves.
        if (!$metadata->idGenerator->isPostInsertGenerator()) {
            $idField          = $metadata->getSingleIdentifierFieldName();
            $idColumn         = $quoteStrategy->getColumnName($idField, $metadata, $platform);
            $data[$idColumn]  = $metadata->idGenerator->generateId($entityManager, $defaults);
            $types[$idColumn] = (string) $metadata->getTypeOfField($idField);
        }

        $connection->insert($quoteStrategy->getTableName($metadata, $platform), $data, $types);
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
            ->orderBy('s.id', SortDirection::Ascending)
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
