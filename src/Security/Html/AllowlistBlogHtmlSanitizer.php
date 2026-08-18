<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Html;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

use function in_array;
use function is_string;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;
use const PHP_URL_HOST;

/**
 * Allowlist sanitizer for CMS article bodies (`allowlist` strategy).
 */
final class AllowlistBlogHtmlSanitizer implements BlogHtmlSanitizerInterface
{
    /** @var array<string, list<string>> */
    private const ALLOWED_ELEMENTS = [
        'p'          => ['class'],
        'br'         => [],
        'strong'     => ['class'],
        'b'          => ['class'],
        'em'         => ['class'],
        'i'          => ['class'],
        'u'          => ['class'],
        's'          => ['class'],
        'del'        => ['class'],
        'h2'         => ['class'],
        'h3'         => ['class'],
        'h4'         => ['class'],
        'ul'         => ['class'],
        'ol'         => ['class'],
        'li'         => ['class'],
        'blockquote' => ['class'],
        'code'       => ['class'],
        'pre'        => ['class'],
        'a'          => ['href', 'title', 'target', 'rel', 'class'],
        'img'        => ['src', 'alt', 'title', 'width', 'height', 'loading', 'class'],
        'table'      => ['class'],
        'thead'      => ['class'],
        'tbody'      => ['class'],
        'tr'         => ['class'],
        'th'         => ['class', 'colspan', 'rowspan'],
        'td'         => ['class', 'colspan', 'rowspan'],
        'hr'         => ['class'],
        'span'       => ['class'],
        'div'        => ['class'],
        'figure'     => ['class'],
        'figcaption' => ['class'],
        'iframe'     => ['src', 'title', 'allow', 'allowfullscreen', 'frameborder', 'width', 'height', 'class'],
    ];

    /** @var list<string> */
    private const ALLOWED_EMBED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'player.vimeo.com',
    ];

    public function sanitize(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document       = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);

        $document->loadHTML(
            sprintf('<?xml encoding="UTF-8"><div>%s</div>', $html),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $container = $document->getElementsByTagName('div')->item(0);

        if (!$container instanceof DOMElement) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); // @codeCoverageIgnore
        }

        $this->sanitizeNode($container);

        $result = '';

        foreach ($container->childNodes as $child) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return $result;
    }

    private function sanitizeNode(DOMElement $node): void
    {
        $child = $node->firstChild;

        while ($child instanceof DOMNode) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (!isset(self::ALLOWED_ELEMENTS[$tag])) {
                    while ($child->firstChild instanceof DOMNode) {
                        $node->insertBefore($child->firstChild, $child);
                    }

                    $node->removeChild($child);
                } else {
                    $this->sanitizeAttributes($child, $tag);
                    $this->sanitizeNode($child);

                    if ($tag === 'iframe' && !$this->isAllowedIframe($child)) {
                        $node->removeChild($child);
                    }
                }
            }

            $child = $next;
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ELEMENTS[$tag];

        if ($element->hasAttributes()) {
            /** @var DOMAttr $attribute */
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->name), $allowed, true)) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }

        if ($tag === 'a') {
            $href = trim($element->getAttribute('href'));
            if (!$this->isAllowedHref($href)) {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($tag === 'img') {
            $src = trim($element->getAttribute('src'));
            if (!$this->isAllowedSrc($src)) {
                $element->removeAttribute('src');
            }
        }
    }

    private function isAllowedIframe(DOMElement $element): bool
    {
        $src  = trim($element->getAttribute('src'));
        $host = parse_url($src, PHP_URL_HOST);

        return is_string($host) && in_array($host, self::ALLOWED_EMBED_HOSTS, true);
    }

    private function isAllowedHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return !str_starts_with($href, '//');
        }

        return (bool) preg_match('#^(https?:|mailto:)#i', $href);
    }

    private function isAllowedSrc(string $src): bool
    {
        if ($src === '' || str_starts_with($src, '//')) {
            return false;
        }

        if (str_starts_with($src, '/')) {
            return true;
        }

        return (bool) preg_match('#^https?://#i', $src);
    }
}
