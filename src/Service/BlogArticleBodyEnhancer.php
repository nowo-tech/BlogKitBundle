<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Turns LinkedIn-style article HTML into a more semantic, visual post body.
 */
final class BlogArticleBodyEnhancer
{
    public function enhance(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $html = $this->mergeAdjacentSingleItemLists($html);
        $html = $this->promoteSectionHeadings($html);
        $html = $this->promoteBlockquotes($html);
        $html = $this->markLeadParagraph($html);
        $html = $this->markDiscussionPrompt($html);

        return $this->decorateHorizontalRules($html);
    }

    private function mergeAdjacentSingleItemLists(string $html): string
    {
        $pattern = '#(?:<ul>\s*<li>(.*?)</li>\s*</ul>\s*){2,}#si';

        return preg_replace_callback(
            $pattern,
            static function (array $match): string {
                preg_match_all('#<ul>\s*<li>(.*?)</li>\s*</ul>#si', $match[0], $items);

                $lis = '';

                foreach ($items[1] as $item) {
                    $lis .= '<li>' . $item . '</li>';
                }

                return '<ul class="blog-article__list">' . $lis . '</ul>';
            },
            $html,
        ) ?? $html;
    }

    private function promoteSectionHeadings(string $html): string
    {
        // After <hr>, promote emoji-led intro paragraphs to headings.
        return preg_replace_callback(
            '#(<hr\b[^>]*>\s*)(<p\b[^>]*>)(.*?)(</p>)#si',
            function (array $match): string {
                $inner = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if (!$this->looksLikeSectionHeading($inner)) {
                    return $match[0];
                }

                return $match[1] . '<h2 class="blog-article__heading">' . $match[3] . '</h2>';
            },
            $html,
        ) ?? $html;
    }

    private function promoteBlockquotes(string $html): string
    {
        // Explicit quoted paragraphs.
        $html = preg_replace_callback(
            '#<p\b[^>]*>(.*?)</p>#si',
            static function (array $match): string {
                $inner = trim($match[1]);
                $plain = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($plain === '') {
                    return $match[0];
                }

                $isQuoted = preg_match('/^[«"“„](.+)[»"”]$/su', $plain) === 1;

                if (!$isQuoted || mb_strlen($plain) > 220) {
                    return $match[0];
                }

                return '<blockquote class="blog-article__quote"><p>' . $inner . '</p></blockquote>';
            },
            $html,
        ) ?? $html;

        // Definition / aphorism introduced by a trailing colon line.
        return preg_replace_callback(
            '#(<p\b[^>]*>[^<]*:\s*</p>\s*)(<p\b[^>]*>)(.*?)(</p>)#si',
            static function (array $match): string {
                $plain = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($plain === '' || mb_strlen($plain) > 180 || str_contains($plain, '?')) {
                    return $match[0];
                }

                return $match[1] . '<blockquote class="blog-article__quote blog-article__quote--pull"><p>' . $match[3] . '</p></blockquote>';
            },
            $html,
        ) ?? $html;
    }

    private function markLeadParagraph(string $html): string
    {
        return preg_replace_callback(
            '#^(\s*)<p\b([^>]*)>#i',
            static function (array $match): string {
                $attrs = $match[2];

                if (preg_match('/\bclass\s*=/', $attrs)) {
                    $attrs = preg_replace(
                        '/\bclass\s*=\s*(["\'])([^"\']*)\1/i',
                        'class=$1blog-article__lead $2$1',
                        $attrs,
                        1,
                    ) ?? $attrs;
                } else {
                    $attrs .= ' class="blog-article__lead"';
                }

                return $match[1] . '<p' . $attrs . '>';
            },
            $html,
            1,
        ) ?? $html;
    }

    private function markDiscussionPrompt(string $html): string
    {
        return preg_replace_callback(
            '#<ul(?:\s+class="blog-article__list")?>\s*<li>(.*?)</li>\s*</ul>#si',
            static function (array $match): string {
                $plain = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if (!str_contains($plain, '?') || mb_strlen($plain) < 24) {
                    return $match[0];
                }

                // Only single-item lists that look like a closing question.
                if (substr_count(strtolower($match[0]), '<li>') > 1) {
                    return $match[0];
                }

                return '<p class="blog-article__prompt">' . $match[1] . '</p>';
            },
            $html,
        ) ?? $html;
    }

    private function decorateHorizontalRules(string $html): string
    {
        return preg_replace('#<hr\b[^>]*>#i', '<hr class="blog-article__rule">', $html) ?? $html;
    }

    private function looksLikeSectionHeading(string $text): bool
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > 110) {
            return false;
        }

        if (!$this->startsWithEmoji($text)) {
            return false;
        }

        // Prefer title-like lines (no long multi-sentence body).
        return substr_count($text, '.') <= 1;
    }

    private function startsWithEmoji(string $text): bool
    {
        return preg_match('/^\p{Extended_Pictographic}/u', $text) === 1;
    }
}
