<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Converts plain-text blog post bodies (LinkedIn export format) into safe HTML fragments.
 */
final readonly class BlogPostBodyFormatter
{
    public function __construct(
        private BlogHashtagProcessor $blogHashtagProcessor,
    ) {
    }

    public function format(string $text): string
    {
        $processed = $this->blogHashtagProcessor->processPlainText($text);
        $text      = $processed['text'];
        $text      = trim(str_replace(["\r\n", "\r"], "\n", $text));

        $blocks = preg_split("/\n{2,}/", $text) ?: [];
        $html   = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (str_contains($block, '⸻') || $block === '---') {
                $html[] = '<hr>';

                continue;
            }

            $lines          = explode("\n", $block);
            $listItems      = [];
            $paragraphLines = [];

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                if (preg_match('/^(✔|❌|👉|[-*•])\s*(.+)$/', $line, $matches)) {
                    if ($paragraphLines !== []) {
                        $html[]         = '<p>' . nl2br(htmlspecialchars(implode("\n", $paragraphLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
                        $paragraphLines = [];
                    }

                    $listItems[] = $matches[2];
                    continue;
                }

                if ($listItems !== []) {
                    $html[]    = $this->renderList($listItems);
                    $listItems = [];
                }

                $paragraphLines[] = $line;
            }

            if ($listItems !== []) {
                $html[] = $this->renderList($listItems);
            }

            if ($paragraphLines !== []) {
                $html[] = '<p>' . nl2br(htmlspecialchars(implode("\n", $paragraphLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            }
        }

        $body = implode("\n", $html);

        if ($processed['hashtags'] !== []) {
            return rtrim($body) . "\n" . $this->blogHashtagProcessor->renderHashtagsHtml($processed['hashtags']);
        }

        return $body;
    }

    /** @param list<string> $items */
    private function renderList(array $items): string
    {
        $html = '<ul>';

        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }

        return $html . '</ul>';
    }
}
