<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Nowo\BlogKitBundle\Service\BlogPostBodyFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BlogHashtagProcessorTest extends TestCase
{
    private BlogHashtagProcessor $processor;

    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Hashtags');
        $translator->method('getLocale')->willReturn('es');

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $params): string => '/blog?' . http_build_query($params),
        );

        $this->processor = new BlogHashtagProcessor($translator, $urls);
    }

    public function testProcessPlainTextSplitsTrailingHashtags(): void
    {
        $result = $this->processor->processPlainText("Hello world?\n\n#PHP\n#Symfony\n… más");
        self::assertStringContainsString('Hello world?', $result['text']);
        self::assertContains('PHP', $result['hashtags']);
        self::assertContains('Symfony', $result['hashtags']);
        self::assertContains('php', $result['tag_slugs']);
        self::assertContains('symfony', $result['tag_slugs']);
    }

    public function testAliasesAndExtract(): void
    {
        self::assertSame('ai', $this->processor->slugForHashtag('IA'));
        self::assertSame('gdpr', $this->processor->slugForHashtag('RGPD'));
        self::assertSame(['AI', 'PHP'], $this->processor->extractHashtags('Talk #AI and #PHP #hashtag'));
        self::assertSame([], $this->processor->extractHashtags(''));
        self::assertSame(['ai' => 'AI'], $this->processor->mapToTagDefinitions(['AI', 'AI']));
    }

    public function testProcessHtmlBodyAndRender(): void
    {
        $html   = '<p>Body</p><p>#PHP #Symfony</p>';
        $result = $this->processor->processHtmlBody($html);
        self::assertStringContainsString('blog-article__hashtags', $result['body']);
        self::assertNotEmpty($result['hashtags']);

        $empty = $this->processor->processHtmlBody('<p>No tags</p>');
        self::assertSame([], $empty['hashtags']);
        self::assertSame('', $this->processor->renderHashtagsHtml([]));
    }

    public function testLocalizeHashtagLinks(): void
    {
        $html = '<a class="blog-hashtag" href="/old" data-hashtag="PHP">#PHP</a>';
        $out  = $this->processor->localizeHashtagLinks($html);
        self::assertStringContainsString('tag=php', $out);
        self::assertSame('plain', $this->processor->localizeHashtagLinks('plain'));
    }

    public function testHashtagFilterUrlFallsBackToSearch(): void
    {
        self::assertStringContainsString('tag=php', $this->processor->hashtagFilterUrl('PHP', 'en'));
        self::assertStringContainsString('q=', $this->processor->hashtagFilterUrl('   ', 'en'));
    }

    public function testFormatterProducesListsAndHashtags(): void
    {
        $formatter = new BlogPostBodyFormatter($this->processor);
        $html      = $formatter->format("Intro para\n\n✔ Item one\n✔ Item two\n\n---\n\nClosing?\n\n#PHP");
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<hr>', $html);
        self::assertStringContainsString('blog-article__hashtags', $html);
        self::assertStringContainsString('<p>', $formatter->format('Solo párrafo'));
    }

    public function testProcessPlainTextSameLineTrailingHashtags(): void
    {
        // "Question? #PHP #Symfony" — same-line trailing hashtags with prefix ending in ?
        $result = $this->processor->processPlainText("Intro.\n\nShould we adopt microservices? #PHP #Symfony");
        self::assertContains('PHP', $result['hashtags']);
        self::assertContains('Symfony', $result['hashtags']);
        // The question line prefix should remain in the body
        self::assertStringContainsString('Should we adopt microservices?', $result['text']);

        // Prefix ending with period: "Interesting topic. #PHP #Symfony"
        $result2 = $this->processor->processPlainText("Intro.\n\nInteresting topic. #React #TypeScript");
        self::assertContains('React', $result2['hashtags']);
        self::assertContains('TypeScript', $result2['hashtags']);
        // The "Interesting topic." sentence should remain in the body
        self::assertStringContainsString('Interesting topic.', $result2['text']);
    }

    public function testExtractHashtagsDuplicateSkipped(): void
    {
        // Duplicate hashtag → only one result
        $result = $this->processor->extractHashtags('#PHP is great. #PHP again.');
        self::assertCount(1, $result);
        self::assertContains('PHP', $result);
    }

    public function testMapToTagDefinitionsSkipsEmptyAndDuplicate(): void
    {
        // Empty slug (pure punctuation) → skipped; duplicate slug → first wins
        $result = $this->processor->mapToTagDefinitions(['PHP', 'PHP', '---']);
        self::assertArrayHasKey('php', $result);
        self::assertCount(1, $result);
    }

    public function testLocalizeHashtagLinksWithoutDataHashtagAttr(): void
    {
        // Link has blog-hashtag class but no data-hashtag attribute → label taken from text
        $html = '<a class="blog-hashtag" href="/old">#PHP</a>';
        $out  = $this->processor->localizeHashtagLinks($html);
        self::assertStringContainsString('tag=php', $out);
    }

    public function testStripTrailingHashtagBlocksNoParas(): void
    {
        // HTML without any <p> tags → returned as-is (trimmed)
        $html = '<div>No paragraphs here</div>';
        self::assertSame($html, $this->processor->stripTrailingHashtagBlocks($html));

        // Completely empty → empty string
        self::assertSame('', $this->processor->stripTrailingHashtagBlocks(''));
    }

    public function testProcessHtmlBodyWithAlreadyFormattedBlock(): void
    {
        // When the HTML already contains a formatted hashtag block, it should be idempotent
        $existingBlock = '<ul class="blog-article__hashtags" aria-label="Hashtags"><li><a href="/blog?tag=php" class="blog-hashtag" data-hashtag="PHP">#PHP</a></li></ul>';
        $html          = '<p>Body text.</p>' . $existingBlock;
        $result        = $this->processor->processHtmlBody($html);
        self::assertContains('PHP', $result['hashtags']);
    }

    public function testProcessHtmlBodyNoParas(): void
    {
        // HTML without paragraph tags → no trailing block found → empty hashtags
        $html   = '<div>Some content #PHP</div>';
        $result = $this->processor->processHtmlBody($html);
        self::assertSame([], $result['hashtags']);
    }
}
