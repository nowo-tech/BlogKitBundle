<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Nowo\BlogKitBundle\Service\BlogPostBodyFormatter;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit coverage for BlogPostBodyFormatter branch paths (REQ-QA-002 climb).
 */
final class BlogPostBodyFormatterTest extends TestCase
{
    private BlogPostBodyFormatter $formatter;

    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Hashtags');
        $translator->method('getLocale')->willReturn('es');

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/blog/tag/x');

        $processor       = new BlogHashtagProcessor($translator, $urls);
        $this->formatter = new BlogPostBodyFormatter($processor);
    }

    #[Test]
    public function testSimpleParagraphIsParagraphWrapped(): void
    {
        $result = $this->formatter->format('Hello world');

        self::assertStringContainsString('<p>', $result);
        self::assertStringContainsString('Hello world', $result);
    }

    #[Test]
    public function testMultipleParagraphsAreSeparated(): void
    {
        $result = $this->formatter->format("First paragraph\n\nSecond paragraph");

        self::assertStringContainsString('<p>First paragraph</p>', $result);
        self::assertStringContainsString('<p>Second paragraph</p>', $result);
    }

    #[Test]
    public function testHorizontalRuleUnicode(): void
    {
        $result = $this->formatter->format("Before\n\n⸻\n\nAfter");

        self::assertStringContainsString('<hr>', $result);
    }

    #[Test]
    public function testHorizontalRuleDashes(): void
    {
        $result = $this->formatter->format("Before\n\n---\n\nAfter");

        self::assertStringContainsString('<hr>', $result);
    }

    #[Test]
    public function testBulletListItemsBecomesUl(): void
    {
        $result = $this->formatter->format("- Item one\n- Item two\n- Item three");

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('<li>Item one</li>', $result);
        self::assertStringContainsString('<li>Item two</li>', $result);
    }

    #[Test]
    public function testCheckmarkIconListItems(): void
    {
        $result = $this->formatter->format("✔ Feature A\n✔ Feature B");

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('Feature A', $result);
    }

    #[Test]
    public function testCrossIconListItems(): void
    {
        $result = $this->formatter->format("❌ Bad thing\n❌ Another bad thing");

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('Bad thing', $result);
    }

    #[Test]
    public function testArrowIconListItems(): void
    {
        $result = $this->formatter->format("👉 Action A\n👉 Action B");

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('Action A', $result);
    }

    #[Test]
    public function testAsteriskListItems(): void
    {
        $result = $this->formatter->format("* First\n* Second");

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('First', $result);
    }

    #[Test]
    public function testBulletDotItems(): void
    {
        $result = $this->formatter->format("• Point one\n• Point two");

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('Point one', $result);
    }

    #[Test]
    public function testMixedParagraphAndList(): void
    {
        $text   = "Introduction paragraph\n- List item one\n- List item two\nMore paragraph text";
        $result = $this->formatter->format($text);

        self::assertStringContainsString('<p>', $result);
        self::assertStringContainsString('<ul>', $result);
    }

    #[Test]
    public function testHashtagsAreAppended(): void
    {
        $text   = "Some content\n\n#PHP\n#Symfony";
        $result = $this->formatter->format($text);

        self::assertStringContainsString('PHP', $result);
    }

    #[Test]
    public function testEmptyStringReturnsEmpty(): void
    {
        $result = $this->formatter->format('');

        self::assertSame('', $result);
    }

    #[Test]
    public function testWindowsLineEndingsAreNormalized(): void
    {
        $result = $this->formatter->format("Line one\r\nLine two");

        self::assertStringContainsString('Line one', $result);
        self::assertStringContainsString('Line two', $result);
    }

    #[Test]
    public function testBlankBlocksAreSkipped(): void
    {
        $result = $this->formatter->format("First\n\n\n\nSecond");

        self::assertStringContainsString('<p>First</p>', $result);
        self::assertStringContainsString('<p>Second</p>', $result);
    }

    #[Test]
    public function testHtmlSpecialCharsAreEscaped(): void
    {
        $result = $this->formatter->format('<script>alert("xss")</script>');

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function testListFollowedByParagraph(): void
    {
        $text   = "- List item\nParagraph after list";
        $result = $this->formatter->format($text);

        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('<p>', $result);
    }

    #[Test]
    public function testMultilineInlineParagraph(): void
    {
        $result = $this->formatter->format("Line one\nLine two\nLine three");

        self::assertStringContainsString('<p>', $result);
        self::assertStringContainsString('Line one', $result);
        self::assertStringContainsString('Line two', $result);
    }
}
