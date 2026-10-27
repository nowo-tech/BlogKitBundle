<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Nowo\BlogKitBundle\Repository\Concerns\RunsDocumentedSql;

/**
 * Prefixes the bundle tables with `doctrine.table_prefix` (REQ-ORM-002): the primary tables **and** the join tables of
 * owning many-to-many associations (`content_blog_article_tag`), which used to stay shared between prefixes
 * (SEC-004 L6). Native SQL resolves the same names from the metadata ({@see RunsDocumentedSql}).
 */
final readonly class TablePrefixListener
{
    public function __construct(
        private string $tablePrefix,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        if ($this->tablePrefix === '') {
            return;
        }

        $metadata = $event->getClassMetadata();
        if (!str_starts_with($metadata->getName(), 'Nowo\\BlogKitBundle\\Entity\\')) {
            return;
        }

        $metadata->setPrimaryTable([
            'name' => $this->tablePrefix . $metadata->getTableName(),
        ]);

        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping instanceof ManyToManyOwningSideMapping && !str_starts_with($mapping->joinTable->name, $this->tablePrefix)) {
                $mapping->joinTable->name = $this->tablePrefix . $mapping->joinTable->name;
            }
        }
    }
}
