<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Html;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Strips every HTML tag (`strip` strategy). The result is plain text **escaped as HTML**: the article body is printed
 * `|raw`, so decoded entities (`&lt;img …&gt;`, or the double-encoded `&amp;lt;img …&amp;gt;` after a persist + render
 * pass) must never become live markup (SEC-004 M2). Running it again on its own output is stable.
 */
final class StripBlogHtmlSanitizer implements BlogHtmlSanitizerInterface
{
    public function sanitize(string $html): string
    {
        // Decode until stable (bounded) so a stored, already escaped body is not escaped twice, then escape once.
        $text = strip_tags($html);
        for ($i = 0; $i < 5; ++$i) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = strip_tags($decoded);
        }

        return trim(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }
}
