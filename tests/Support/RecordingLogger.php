<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR-3 logger for asserting what the bundle logs.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
