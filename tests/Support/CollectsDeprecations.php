<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Since 1.12.0: runs a callable and returns the `E_USER_DEPRECATED` messages it triggered (silenced ones included, as
 * `trigger_deprecation()` silences them), in order. Every other error goes to the previous handler.
 */
trait CollectsDeprecations
{
    /**
     * @param callable(): mixed $operation
     *
     * @return list<string>
     */
    private static function deprecationsOf(callable $operation): array
    {
        $messages = [];
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$messages, &$previous): bool {
            if ($level === E_USER_DEPRECATED) {
                $messages[] = $message;

                return true;
            }

            return $previous !== null && (bool) $previous($level, $message, $file, $line);
        });

        try {
            $operation();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
