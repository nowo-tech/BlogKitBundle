<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Nowo\TransactionDoctrine\PrivateManager\TransactionLevelListenerInterface;

/**
 * Since 1.12.0: a private manager created after the connection its middleware wraps (the real-database tests): this
 * listener forwards to it.
 */
final class ForwardingLevelListener implements TransactionLevelListenerInterface
{
    public ?TransactionLevelListenerInterface $target = null;

    public function connectionCommitted(): void
    {
        $this->target?->connectionCommitted();
    }

    public function connectionRolledBack(?int $level = 1): void
    {
        $this->target?->connectionRolledBack($level);
    }

    public function savepointReleased(int $level): void
    {
        $this->target?->savepointReleased($level);
    }
}
