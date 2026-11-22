<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

/**
 * {@see HostPendingChangesTestCase} on SQLite (the default test run).
 */
final class HostPendingChangesKernelTest extends HostPendingChangesTestCase
{
    protected function databaseUrl(): ?string
    {
        return null;
    }
}
