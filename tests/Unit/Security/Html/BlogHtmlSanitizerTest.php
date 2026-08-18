<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security\Html;

use Nowo\BlogKitBundle\Security\Html\AllowlistBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\Html\NullBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\Html\StripBlogHtmlSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogHtmlSanitizerTest extends TestCase
{
    #[Test]
    public function nullStrategyReturnsInput(): void
    {
        self::assertSame('<script>x</script>', (new NullBlogHtmlSanitizer())->sanitize('<script>x</script>'));
    }

    #[Test]
    public function stripStrategyRemovesTags(): void
    {
        self::assertSame('Hello', (new StripBlogHtmlSanitizer())->sanitize(' <p>Hello</p> '));
    }

    #[Test]
    public function allowlistStripsScriptsHandlersAndJavascriptUrls(): void
    {
        $sanitizer = new AllowlistBlogHtmlSanitizer();
        $result    = $sanitizer->sanitize(
            '<p onclick="evil()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
        );

        self::assertStringContainsString('<p>Hi</p>', $result);
        self::assertStringNotContainsString('script', strtolower($result));
        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function allowlistKeepsSafeLinksImagesAndYoutube(): void
    {
        $sanitizer = new AllowlistBlogHtmlSanitizer();
        $result    = $sanitizer->sanitize(
            '<a href="https://example.com">Ex</a><a href="/privacy">P</a><a href="mailto:a@b.c">M</a>'
            . '<img src="/hero.png" alt="h"><img src="https://cdn.example/a.png" alt="c">'
            . '<iframe src="https://www.youtube.com/embed/abc"></iframe>',
        );

        self::assertStringContainsString('https://example.com', $result);
        self::assertStringContainsString('/privacy', $result);
        self::assertStringContainsString('mailto:a@b.c', $result);
        self::assertStringContainsString('/hero.png', $result);
        self::assertStringContainsString('https://cdn.example/a.png', $result);
        self::assertStringContainsString('youtube.com', $result);
        self::assertStringContainsString('noopener', $result);
    }

    #[Test]
    public function allowlistDropsUnsafeMediaAndProtocolRelativeUrls(): void
    {
        $sanitizer = new AllowlistBlogHtmlSanitizer();
        $result    = $sanitizer->sanitize(
            '<a href="//evil.example">x</a><a href="">empty</a>'
            . '<img src="javascript:alert(1)"><img src="//cdn.example/x.png">'
            . '<iframe src="https://evil.example/embed"></iframe><iframe></iframe>',
        );

        self::assertStringNotContainsString('evil.example', $result);
        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringNotContainsString('iframe', strtolower($result));
    }

    #[Test]
    public function allowlistReturnsEmptyForBlankInput(): void
    {
        self::assertSame('', (new AllowlistBlogHtmlSanitizer())->sanitize('   '));
    }

    #[Test]
    public function allowlistKeepsNestedSafeMarkupAndApprovedEmbeds(): void
    {
        $sanitizer = new AllowlistBlogHtmlSanitizer();
        $result    = $sanitizer->sanitize(
            '<div><p>Hi</p><br><hr><span class="x">n</span></div>'
            . '<iframe src="https://player.vimeo.com/video/1"></iframe>'
            . '<iframe src="https://www.youtube-nocookie.com/embed/a"></iframe>'
            . '<unknown><em>keep</em></unknown>',
        );

        self::assertStringContainsString('<p>Hi</p>', $result);
        self::assertStringContainsString('<em>keep</em>', $result);
        self::assertStringContainsString('player.vimeo.com', $result);
        self::assertStringContainsString('youtube-nocookie.com', $result);
        self::assertStringNotContainsString('unknown', $result);
    }
}
