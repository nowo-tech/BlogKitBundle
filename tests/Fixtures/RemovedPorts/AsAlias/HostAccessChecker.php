<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\AsAlias;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A loadable host class aliased to the id of the object access port removed in 2.0 (W1-1): 2.0 never reads that id,
 * so the host's access rule would silently not apply.
 */
#[AsAlias('Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface')]
final class HostAccessChecker
{
}
