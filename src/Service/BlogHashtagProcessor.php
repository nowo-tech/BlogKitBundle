<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function count;
use function in_array;
use function is_string;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PREG_OFFSET_CAPTURE;

/**
 * Extracts LinkedIn-style trailing hashtags, formats them as HTML, and maps them to blog tag slugs.
 */
final readonly class BlogHashtagProcessor
{
    /**
     * Optional aliases: normalized hashtag label => canonical tag slug.
     * Everything else maps 1:1 (each LinkedIn hashtag becomes its own blog tag).
     *
     * @var array<string, string>
     */
    private const array HASHTAG_ALIASES = [
        'ia'   => 'ai',
        'rgpd' => 'gdpr',
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{body: string, hashtags: list<string>, tag_slugs: list<string>}
     */
    public function processHtmlBody(string $html): array
    {
        $hashtags  = $this->extractHashtags($this->extractTrailingHashtagSource($html));
        $body      = $this->stripTrailingHashtagBlocks($html);
        $formatted = $this->renderHashtagsHtml($hashtags);

        if ($hashtags === []) {
            return [
                'body'      => trim($body),
                'hashtags'  => [],
                'tag_slugs' => [],
            ];
        }

        $next = rtrim($body) . "\n" . $formatted;

        return [
            'body'      => $next,
            'hashtags'  => $hashtags,
            'tag_slugs' => $this->mapToTagSlugs($hashtags),
        ];
    }

    /**
     * Process plain LinkedIn text (before HTML conversion): split body vs trailing tags.
     *
     * @return array{text: string, hashtags: list<string>, tag_slugs: list<string>}
     */
    public function processPlainText(string $text): array
    {
        $text     = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $hashtags = [];

        // Trailing block: lines that are only "hashtag", "#Tag", or "… más"
        $lines = explode("\n", $text);
        $cut   = count($lines);

        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $line = trim($lines[$i]);

            if ($line === '') {
                $cut = $i;
                continue;
            }

            if (preg_match('/^(?:hashtag|#\S+|…\s*más|\.\.\.\s*más|…\s*more)$/iu', $line)) {
                if (str_starts_with($line, '#')) {
                    $label = $this->displayLabel($line);
                    $key   = $this->normalizeLabel($label);

                    if ($key !== '' && !isset($hashtags[$key])) {
                        $hashtags[$key] = $label;
                    }
                }

                $cut = $i;
                continue;
            }

            // Same-line trailing hashtags: "...question? #PHP #Symfony"
            if (preg_match('/^(.*?)(?:\s+#\w[\w+#-]*)+\s*$/u', $line, $m) && preg_match('/#\w/', $line)) {
                $prefix = trim($m[1]);

                if ($prefix === '' || str_ends_with($prefix, '?') || str_ends_with($prefix, '.')) {
                    foreach ($this->extractHashtags($line) as $label) {
                        $key = $this->normalizeLabel($label);

                        if ($key !== '' && !isset($hashtags[$key])) {
                            $hashtags[$key] = $label;
                        }
                    }

                    if ($prefix !== '') {
                        $lines[$i] = $prefix;
                    }
                    // Empty prefix: whole line is trailing hashtags (exclude it). Non-empty: keep prefix.
                    $cut = $prefix === '' ? $i : $i + 1;

                    break;
                }
            }

            break;
        }

        $bodyLines = array_slice($lines, 0, $cut);
        $display   = array_values($hashtags);

        return [
            'text'      => trim(implode("\n", $bodyLines)),
            'hashtags'  => $display,
            'tag_slugs' => $this->mapToTagSlugs($display),
        ];
    }

    /**
     * @return list<string> Display labels without #
     */
    public function extractHashtags(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $found = [];

        if (preg_match_all('/#([A-Za-zÁÉÍÓÚÜÑáéíóúüñ][A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ_+-]{0,48})/u', $content, $matches)) {
            foreach ($matches[1] as $raw) {
                $label = $this->displayLabel($raw);
                $key   = $this->normalizeLabel($label);

                if (isset($found[$key])) {
                    continue;
                }

                if (in_array($key, [
                    'hashtag',
                    'mas',
                    'más',
                    'more'], true)) {
                    continue;
                }

                $found[$key] = $label;
            }
        }

        return array_values($found);
    }

    /**
     * @param list<string> $hashtags Display labels without #
     *
     * @return list<string>
     */
    public function mapToTagSlugs(array $hashtags): array
    {
        $slugs = [];

        foreach ($hashtags as $hashtag) {
            $slug = $this->slugForHashtag($hashtag);

            if ($slug === '') {
                continue;
            }

            if (isset($slugs[$slug])) {
                continue;
            }

            $slugs[$slug] = $slug;
        }

        return array_values($slugs);
    }

    /**
     * @param list<string> $hashtags Display labels without #
     *
     * @return array<string, string> slug => preferred display name
     */
    public function mapToTagDefinitions(array $hashtags): array
    {
        $definitions = [];

        foreach ($hashtags as $hashtag) {
            $slug = $this->slugForHashtag($hashtag);
            $name = $this->displayLabel($hashtag);

            if ($slug === '') {
                continue;
            }

            if (!isset($definitions[$slug])) {
                $definitions[$slug] = $name;
            }
        }

        return $definitions;
    }

    public function slugForHashtag(string $label): string
    {
        $key = $this->normalizeLabel($label);

        if ($key === '') {
            return '';
        }

        return self::HASHTAG_ALIASES[$key] ?? $key;
    }

    /**
     * @param list<string> $hashtags Display labels without #
     */
    public function renderHashtagsHtml(array $hashtags): string
    {
        if ($hashtags === []) {
            return '';
        }

        $items = '';

        foreach ($hashtags as $hashtag) {
            $safe = htmlspecialchars($hashtag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $href = htmlspecialchars($this->hashtagFilterUrl($hashtag), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $items .= '<li><a href="' . $href . '" class="blog-hashtag" data-hashtag="' . $safe . '">#' . $safe . '</a></li>';
        }

        $aria = htmlspecialchars(
            $this->translator->trans('blog.article.hashtags_label'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        return '<ul class="blog-article__hashtags" aria-label="' . $aria . '">' . $items . '</ul>';
    }

    /**
     * Rewrites hashtag hrefs for the active locale (tag filter or search).
     */
    public function localizeHashtagLinks(string $html): string
    {
        if (!str_contains($html, 'blog-hashtag')) {
            return $html;
        }

        $result = preg_replace_callback(
            '#<a\b([^>]*\bblog-hashtag\b[^>]*)>(\#[^<]+)</a>#i',
            function (array $matches): string {
                $attrs = $matches[1];
                $text  = $matches[2];
                $label = ltrim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '#');

                if (preg_match('/\bdata-hashtag="([^"]*)"/i', $attrs, $dataMatch)) {
                    $label = html_entity_decode($dataMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                $href      = htmlspecialchars($this->hashtagFilterUrl($label), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $attrs     = preg_replace('/\s*href="[^"]*"/i', '', $attrs) ?? $attrs;
                $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                if (!preg_match('/\bdata-hashtag=/i', $attrs)) {
                    $attrs .= ' data-hashtag="' . $safeLabel . '"';
                }

                return '<a href="' . $href . '"' . $attrs . '>#' . $safeLabel . '</a>';
            },
            $html,
        );

        return is_string($result) ? $result : $html;
    }

    public function hashtagFilterUrl(string $label, ?string $locale = null): string
    {
        $locale ??= $this->translator->getLocale();
        $tagSlug = $this->slugForHashtag($label);

        if ($tagSlug !== '') {
            return $this->urlGenerator->generate('blog_index', [
                '_locale' => $locale,
                'tag'     => $tagSlug,
            ]);
        }

        return $this->urlGenerator->generate('blog_index', [
            '_locale' => $locale,
            'q'       => $this->displayLabel($label),
        ]);
    }

    public function stripTrailingHashtagBlocks(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $html = preg_replace(
            '#\s*<ul class="blog-article__hashtags"[^>]*>.*?</ul>\s*#si',
            "\n",
            $html,
        ) ?? $html;

        if (!preg_match_all('#<p\b[^>]*>.*?</p>#si', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return trim($html);
        }

        $paragraphs = $matches[0];
        $removeFrom = count($paragraphs);

        for ($i = count($paragraphs) - 1; $i >= 0; --$i) {
            $paragraphHtml = $paragraphs[$i][0];

            if ($this->isHashtagParagraph($paragraphHtml)) {
                $removeFrom = $i;
                continue;
            }

            break;
        }

        if ($removeFrom >= count($paragraphs)) {
            return trim($html);
        }

        $cutOffset = $paragraphs[$removeFrom][1];

        return trim(substr($html, 0, $cutOffset));
    }

    private function extractTrailingHashtagSource(string $html): string
    {
        // Prefer already-formatted block (idempotent re-runs)
        if (preg_match('#<ul class="blog-article__hashtags"[^>]*>.*?</ul>#si', $html, $m)) {
            return $m[0];
        }

        if (!preg_match_all('#<p\b[^>]*>.*?</p>#si', $html, $matches)) {
            return '';
        }

        $trailing = [];
        $all      = $matches[0];

        for ($i = count($all) - 1; $i >= 0; --$i) {
            if ($this->isHashtagParagraph($all[$i])) {
                array_unshift($trailing, $all[$i]);
                continue;
            }

            break;
        }

        return implode("\n", $trailing);
    }

    private function isHashtagParagraph(string $paragraphHtml): bool
    {
        $text = str_ireplace([
            '<br>',
            '<br/>',
            '<br />'], "\n", $paragraphHtml);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '' || !preg_match('/#\w/u', $text)) {
            return false;
        }

        $rest = preg_replace('/hashtag/iu', '', $text) ?? '';
        $rest = preg_replace('/#\S+/u', '', $rest) ?? '';
        $rest = preg_replace('/…\s*más|\.\.\.\s*más|…\s*more|\.\.\./iu', '', $rest) ?? '';
        $rest = trim(preg_replace('/\s+/u', '', $rest) ?? '');

        return $rest === '';
    }

    private function normalizeLabel(string $raw): string
    {
        $raw = trim($raw);
        $raw = ltrim($raw, '#');
        $raw = strtr($raw, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ]);
        $raw = strtolower($raw);

        return preg_replace('/[^a-z0-9+]/', '', $raw) ?? '';
    }

    private function displayLabel(string $raw): string
    {
        return ltrim(trim($raw), '#');
    }
}
