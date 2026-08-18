<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Nowo\BlogKitBundle\Service\BlogArticleBodyEnhancer;
use PHPUnit\Framework\TestCase;

final class BlogArticleBodyEnhancerTest extends TestCase
{
    private BlogArticleBodyEnhancer $enhancer;

    protected function setUp(): void
    {
        $this->enhancer = new BlogArticleBodyEnhancer();
    }

    public function testEmptyHtmlReturnsEmptyString(): void
    {
        self::assertSame('', $this->enhancer->enhance(''));
        self::assertSame('', $this->enhancer->enhance('   '));
    }

    public function testMergeAdjacentSingleItemLists(): void
    {
        $html = '<ul><li>One</li></ul><ul><li>Two</li></ul><ul><li>Three</li></ul>';
        $out  = $this->enhancer->enhance($html);
        self::assertStringContainsString('blog-article__list', $out);
        self::assertStringContainsString('<li>One</li>', $out);
        self::assertStringContainsString('<li>Two</li>', $out);
        self::assertSame(1, substr_count($out, '<ul'));
    }

    public function testPromoteSectionHeadingsAfterHr(): void
    {
        $html = '<hr><p>🚀 Launch section</p><p>Body copy.</p>';
        $out  = $this->enhancer->enhance($html);
        self::assertStringContainsString('blog-article__heading', $out);
        self::assertStringContainsString('blog-article__rule', $out);
    }

    public function testPromoteBlockquotes(): void
    {
        $html = '<p>«Short quoted line»</p><p>Term:</p><p>Definition without question mark</p>';
        $out  = $this->enhancer->enhance($html);
        self::assertStringContainsString('blog-article__quote', $out);
    }

    public function testMarkLeadParagraph(): void
    {
        $out = $this->enhancer->enhance('<p>Opening paragraph</p>');
        self::assertStringContainsString('blog-article__lead', $out);
    }

    public function testDiscussionPromptAndHrDecorate(): void
    {
        $html = '<p>Intro</p><ul><li>What do you think about this topic today?</li></ul><hr>';
        $out  = $this->enhancer->enhance($html);
        self::assertStringContainsString('blog-article__prompt', $out);
        self::assertStringContainsString('class="blog-article__rule"', $out);
    }

    public function testPromoteSectionHeadingsKeepsNonHeadingParagraph(): void
    {
        // Paragraph after <hr> that is too long / has no emoji → stays as <p>
        $html = '<hr><p>This is a regular paragraph without an emoji and it is just normal body text.</p>';
        $out  = $this->enhancer->enhance($html);
        self::assertStringNotContainsString('blog-article__heading', $out);
        self::assertStringContainsString('<p', $out);
    }

    public function testPromoteBlockquotesSkipsEmptyAndTooLong(): void
    {
        // Empty inner → not converted
        $out = $this->enhancer->enhance('<p></p><p>Follow-up</p>');
        self::assertStringNotContainsString('blockquote', $out);

        // Too-long definition aphorism (> 180 chars) → not converted
        $longDef = str_repeat('x ', 100);
        $out2    = $this->enhancer->enhance("<p>Definition:</p><p>{$longDef}</p>");
        self::assertStringNotContainsString('blockquote', $out2);

        // Definition with a question mark → not converted
        $out3 = $this->enhancer->enhance('<p>Term:</p><p>Has a question?</p>');
        self::assertStringNotContainsString('blockquote', $out3);
    }

    public function testMarkLeadParagraphPreservesExistingClass(): void
    {
        $out = $this->enhancer->enhance('<p class="intro">Opening with existing class</p>');
        self::assertStringContainsString('blog-article__lead', $out);
        self::assertStringContainsString('intro', $out);
    }

    public function testMarkDiscussionPromptSkipsMultiItemList(): void
    {
        $html = '<p>Intro</p><ul><li>Item 1</li><li>What if we had two items here?</li></ul>';
        $out  = $this->enhancer->enhance($html);
        self::assertStringNotContainsString('blog-article__prompt', $out);
    }

    public function testLooksLikeSectionHeadingSkipsNoEmojiAndTooLong(): void
    {
        // No emoji → not promoted to heading
        $html = '<hr><p>No emoji at the start of this section header text</p>';
        $out  = $this->enhancer->enhance($html);
        self::assertStringNotContainsString('blog-article__heading', $out);

        // Too long (> 110 chars) → not promoted
        $html2 = '<hr><p>🚀 ' . str_repeat('x', 112) . '</p>';
        $out2  = $this->enhancer->enhance($html2);
        self::assertStringNotContainsString('blog-article__heading', $out2);
    }
}
