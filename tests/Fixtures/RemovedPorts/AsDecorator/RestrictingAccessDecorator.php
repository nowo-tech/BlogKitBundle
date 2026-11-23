<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\AsDecorator;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * A loadable host decorator of the 1.x object access checker id (removed in 2.0) that restricted admin access (W1-1):
 * dropped, it would widen access to the blog administration.
 */
#[AsDecorator('Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface')]
final readonly class RestrictingAccessDecorator
{
    public function __construct(#[AutowireDecorated] public ?object $inner = null)
    {
    }
}
