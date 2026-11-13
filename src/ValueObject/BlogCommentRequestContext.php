<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 3): the parts of the HTTP request a comment port may need, built per call by the
 * bundle's controller. Never a cookie, a header bag or the session.
 */
final readonly class BlogCommentRequestContext
{
    /**
     * @param array<string, string> $fields the submitted top-level string fields of the request body (no files)
     */
    public function __construct(
        public ?string $clientIp,
        public string $host,
        public string $method = 'POST',
        public string $path = '/',
        public array $fields = [],
    ) {
    }

    public function field(string $name): string
    {
        return $this->fields[$name] ?? '';
    }
}
