<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use DG\BypassFinals;

use function restore_error_handler;
use function set_error_handler;
use function stream_wrapper_restore;
use function trigger_error;

use const E_DEPRECATED;
use const E_USER_DEPRECATED;

/**
 * Runs a kept-alive request loop whose memory is measured without the test harness' own per-request bookkeeping.
 *
 * Two things grow with every request inside PHPUnit and never in a worker:
 *
 * - `DG\BypassFinals` (enabled in tests/bootstrap.php) replaces the `file://` stream wrapper with a userland one, and
 *   every file operation the kernel makes per request goes through it: about 580 bytes per request
 *   that are never given back, and a process-wide table that doubles once it is big enough (+10.5 MB in one step on
 *   CI, Symfony 7.4, once the whole suite had filled it). Outside PHPUnit the same listing loop stays at 0 bytes per
 *   request over 4,000 requests.
 * - PHPUnit keeps one event per triggered deprecation (Symfony 7.4 triggers one per request for the test user's
 *   `eraseCredentials()`): about 400 bytes per request.
 *
 * So the loop runs with PHP's native `file://` wrapper, and each distinct deprecation is collected and triggered once
 * after the loop (it is still reported). Everything the kernel and the bundle keep across requests is still measured.
 */
final class KeptAliveMemory
{
    /**
     * @param callable(): void $loop
     */
    public static function run(callable $loop): void
    {
        /** @var array<string, string> $deprecations */
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[$message] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        stream_wrapper_restore('file');

        try {
            $loop();
        } finally {
            BypassFinals::enable();
            restore_error_handler();
        }

        foreach ($deprecations as $message) {
            trigger_error($message, E_USER_DEPRECATED);
        }
    }
}
