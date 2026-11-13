<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Port;

use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Since 1.9.0: builds the {@see BlogCommentRequestContext} the request-free ports receive, per call (REQ-RUNTIME-002).
 * Only the top-level string fields of the body are copied (no files, no nested arrays, no cookies).
 */
final class BlogCommentRequestContexts
{
    public static function fromRequest(Request $request): BlogCommentRequestContext
    {
        $fields = [];
        foreach ($request->request->all() as $name => $value) {
            if (is_string($value)) {
                $fields[(string) $name] = $value;
            }
        }

        return new BlogCommentRequestContext(
            $request->getClientIp(),
            $request->getHost(),
            $request->getMethod(),
            $request->getPathInfo(),
            $fields,
        );
    }
}
