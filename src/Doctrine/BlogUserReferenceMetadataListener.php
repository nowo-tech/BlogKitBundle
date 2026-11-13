<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Nowo\BlogKitBundle\Doctrine\Type\BlogUserRefType;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function preg_match;
use function sha1;
use function substr;
use function version_compare;

/**
 * Since 1.9.0 (`doctrine.user_reference: id`, REQ-ARCH-001 rule 2): maps the user fields of the blog rows as string
 * columns holding the user's id ({@see BlogUserRefType}) instead of relations to `user_class`. Same column name and
 * nullability as the join column of 1.8, plus an index of its own.
 *
 * REQ-SEC-006 item 3: on MySQL / MariaDB the column compares byte for byte with NO PAD: `utf8mb4_nopad_bin` on MariaDB,
 * `utf8mb4_0900_bin` on MySQL 8.0.17+, and `utf8mb4_bin` (PAD SPACE; ids with edge spaces are refused anyway) only on
 * an older server or when the server version cannot be read in full, with a logged warning (item 8).
 *
 * Registered only in the id mode; stateless.
 */
final readonly class BlogUserReferenceMetadataListener
{
    /** @var array<class-string, list<string>> */
    public const array USER_FIELDS = [
        BlogArticle::class => ['createdBy', 'updatedBy'],
        BlogComment::class => ['staffAuthor', 'moderatedBy'],
    ];

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $fields   = self::USER_FIELDS[$metadata->getName()] ?? [];
        if ($fields === []) {
            return;
        }

        $connection = $args->getObjectManager()->getConnection();
        $collation  = $this->collation($connection->getDatabasePlatform(), $connection);
        foreach ($fields as $field) {
            if (isset($metadata->associationMappings[$field])) {
                $this->mapUserReference($metadata, $field, $collation);
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function mapUserReference(ClassMetadata $metadata, string $fieldName, ?string $collation): void
    {
        $mapping = $metadata->associationMappings[$fieldName];
        $join    = $mapping instanceof ToOneOwningSideMapping ? ($mapping->joinColumns[0] ?? null) : null;
        $column  = $join instanceof JoinColumnMapping ? $join->name : $fieldName . '_id';
        unset($metadata->associationMappings[$fieldName]);

        $field = [
            'fieldName'  => $fieldName,
            'type'       => BlogUserRefType::NAME,
            'columnName' => $column,
            'length'     => BlogUserRef::MAX_LENGTH,
            'nullable'   => true,
        ];
        if ($collation !== null) {
            $field['options'] = ['collation' => $collation];
        }

        $metadata->mapField($field);
        $metadata->table['indexes']['idx_blog_user_' . substr(sha1($metadata->getTableName() . '.' . $column), 0, 12)] = ['columns' => [$column]];
    }

    /**
     * The binary NO PAD collation of the platform (null: the platform compares exactly by default).
     */
    public function collation(AbstractPlatform $platform, ?Connection $connection = null): ?string
    {
        if ($platform instanceof MariaDBPlatform) {
            return 'utf8mb4_nopad_bin';
        }

        if (!$platform instanceof AbstractMySQLPlatform) {
            return null;
        }

        $version = null;
        try {
            $version = $connection?->getServerVersion();
        } catch (Throwable) {
            // Unknown: falls back below.
        }

        if ($version !== null && preg_match('/^(\d+)\.(\d+)\.(\d+)/D', $version, $parts) === 1 && version_compare($parts[1] . '.' . $parts[2] . '.' . $parts[3], '8.0.17', '>=')) {
            return 'utf8mb4_0900_bin';
        }

        $this->logger->warning('BlogKit: the MySQL server version ({version}) is older than 8.0.17 or has no patch number, so the user id columns use utf8mb4_bin (PAD SPACE) instead of utf8mb4_0900_bin (REQ-SEC-006). Ids with edge spaces are refused anyway.', ['bundle' => 'blog_kit', 'version' => $version ?? 'unknown']);

        return 'utf8mb4_bin';
    }
}
