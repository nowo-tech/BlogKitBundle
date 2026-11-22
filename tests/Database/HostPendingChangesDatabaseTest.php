<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Database;

use Nowo\BlogKitBundle\Tests\Integration\Worker\HostPendingChangesTestCase;
use PHPUnit\Framework\Attributes\Group;

use function getenv;
use function is_string;

/**
 * {@see HostPendingChangesTestCase} on a real server (CI job "Databases": MySQL 8.4, MariaDB 11.4, PostgreSQL 16; DBAL
 * 4 and 3): `BLOG_KIT_DATABASE_URL`, else skipped.
 */
#[Group('database')]
final class HostPendingChangesDatabaseTest extends HostPendingChangesTestCase
{
    protected function databaseUrl(): string
    {
        $url = getenv('BLOG_KIT_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('BLOG_KIT_DATABASE_URL is not set (CI job "Databases" only).');
        }

        return $url;
    }
}
