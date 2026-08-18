<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Html;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Strips every HTML tag (`strip` strategy).
 */
final class StripBlogHtmlSanitizer implements BlogHtmlSanitizerInterface
{
    public function sanitize(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
