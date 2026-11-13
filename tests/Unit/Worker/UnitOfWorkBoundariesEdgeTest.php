<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Worker;

use Nowo\BlogKitBundle\Worker\UnitOfWorkBoundaries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Edge cases of the tracker (1.9.0): sub-requests are not units, and an unknown command's error is judged on the
 * current state.
 */
final class UnitOfWorkBoundariesEdgeTest extends TestCase
{
    #[Test]
    public function subRequestsAndUnknownCommandsLeaveTheStateAlone(): void
    {
        $boundaries = new UnitOfWorkBoundaries();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $boundaries->onRequest(new RequestEvent($kernel, Request::create('/fragment'), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $boundaries->requestsInFlight());

        $error = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('command not found'));
        $boundaries->onError($error);
        self::assertTrue($boundaries->isOutermost($error));
        self::assertSame(0, $boundaries->runningCommands());
    }
}
