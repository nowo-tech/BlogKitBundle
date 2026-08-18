<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Html;

/**
 * Leaves HTML unchanged (`none` strategy). Trusted editors only.
 */
final class NullBlogHtmlSanitizer implements BlogHtmlSanitizerInterface
{
    public function sanitize(string $html): string
    {
        return $html;
    }
}
