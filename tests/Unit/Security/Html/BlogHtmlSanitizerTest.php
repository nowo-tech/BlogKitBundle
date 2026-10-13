<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security\Html;

use Nowo\BlogKitBundle\Security\Html\AllowlistBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\Html\NullBlogHtmlSanitizer;
use Nowo\BlogKitBundle\Security\Html\StripBlogHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function allowlistSanitizesChildrenOfUnwrappedElements(): void
    {
        $result = new AllowlistBlogHtmlSanitizer()->sanitize(
            '<section><script>alert(1)</script><img src="x" onerror="alert(2)">'
            . '<a href="javascript:alert(3)">x</a><meta http-equiv="refresh" content="0;url=https://evil.test">'
            . '<article><section><style>body{display:none}</style><p onclick="a()">deep</p></section></article></section>',
        );

        foreach (['script', 'alert', 'onerror', 'javascript:', 'meta', 'refresh', 'style', 'onclick', 'section', 'article'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $result, $forbidden);
        }
        self::assertStringContainsString('<p>deep</p>', $result);
    }

    #[Test]
    public function allowlistRejectsBackslashProtocolRelativeUrls(): void
    {
        $result = new AllowlistBlogHtmlSanitizer()->sanitize('<a href="/\\evil.test">x</a><img src="/\\evil.test/a.png" alt="a"><a href="/ok">ok</a>');

        self::assertStringNotContainsString('evil.test', $result);
        self::assertStringContainsString('href="/ok"', $result);
    }

    #[Test]
    public function allowlistRejectsProtocolRelativeUrlsSplitByUrlWhitespace(): void
    {
        $result = new AllowlistBlogHtmlSanitizer()->sanitize(
            '<a href="/&#9;/evil.test">x</a><a href="/&#10;\\evil.test">y</a><img src="/&#13;/evil.test/a.png" alt="a">',
        );

        self::assertStringNotContainsString('evil.test', $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedIframeSources(): iterable
    {
        yield 'javascript scheme with allowed host' => ['javascript://www.youtube.com/%0Aalert(document.cookie)'];
        yield 'uppercase javascript scheme' => ['JAVASCRIPT://www.youtube.com/%0Aalert(1)'];
        yield 'entity-encoded javascript scheme' => ['&#106;avascript://www.youtube.com/%0Aalert(1)'];
        yield 'tab inside scheme' => ["java\tscript://www.youtube.com/%0Aalert(1)"];
        yield 'data scheme' => ['data://www.youtube.com/text/html,x'];
        yield 'plain http' => ['http://www.youtube.com/embed/abc'];
        yield 'protocol-relative' => ['//www.youtube.com/embed/abc'];
        yield 'backslash authority' => ['https://evil.example\@www.youtube.com/embed/abc'];
        yield 'userinfo' => ['https://evil.example@www.youtube.com/embed/abc'];
        yield 'unparsable' => ['https:///embed/abc'];
    }

    #[Test]
    #[DataProvider('rejectedIframeSources')]
    public function allowlistRejectsIframeSourcesThatAreNotHttpsOnAllowedHost(string $src): void
    {
        $result = (new AllowlistBlogHtmlSanitizer())->sanitize('<p>v</p><iframe src="' . $src . '"></iframe>');

        self::assertStringNotContainsString('iframe', strtolower($result));
        self::assertStringNotContainsString('alert', $result);
        self::assertStringContainsString('<p>v</p>', $result);
    }

    #[Test]
    public function allowlistKeepsHttpsEmbedWithUppercaseHost(): void
    {
        $result = (new AllowlistBlogHtmlSanitizer())->sanitize('<iframe src="HTTPS://WWW.YOUTUBE.COM/embed/abc"></iframe>');

        self::assertStringContainsString('<iframe src="HTTPS://WWW.YOUTUBE.COM/embed/abc"></iframe>', $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedLinkAndImageUrls(): iterable
    {
        yield 'decimal entity javascript' => ['&#106;avascript:alert(1)'];
        yield 'hex entity javascript' => ['&#x6A;avascript:alert(1)'];
        yield 'tab inside scheme' => ["java\tscript:alert(1)"];
        yield 'leading space mixed case' => ['  JaVaScRiPt:alert(1)'];
        yield 'vbscript' => ['vbscript:alert(1)'];
        yield 'data html' => ['data:text/html,alert(1)'];
    }

    #[Test]
    #[DataProvider('rejectedLinkAndImageUrls')]
    public function allowlistRejectsDangerousHrefAndSrcSchemes(string $url): void
    {
        $sanitizer = new AllowlistBlogHtmlSanitizer();

        foreach (['<a href="' . $url . '">x</a>', "<a href='" . $url . "'>x</a>", '<img src="' . $url . '">'] as $html) {
            $result = $sanitizer->sanitize($html);

            self::assertStringNotContainsString('href=', $result);
            self::assertStringNotContainsString('src=', $result);
            self::assertStringNotContainsString('alert', $result);
        }
    }
}
