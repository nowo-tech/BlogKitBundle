<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\ImplementsAccess;

use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;

/**
 * A 1.x host object access checker still on the port removed in 2.0 (W1-1): the class cannot be loaded any more, so
 * Symfony keeps its autodiscovered definition with a loading error only. Excluded from Rector (never loadable).
 */
final class LegacyOwnerChecker implements BlogKitResourceAccessCheckerInterface
{
}
