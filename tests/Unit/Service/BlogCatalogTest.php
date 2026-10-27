<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Dom\HTMLDocument;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Nowo\BlogKitBundle\Service\BlogArticleBodyEnhancer;
use Nowo\BlogKitBundle\Service\BlogCatalog;
use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Nowo\BlogKitBundle\Service\BlogLocalesLocaleResolver;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Tests\Support\BlogProtectionTestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function http_build_query;

use const LIBXML_NOERROR;

final class BlogCatalogTest extends TestCase
{
    #[Test]
    public function itResolvesLocaleFromCurrentRequest(): void
    {
        $request = Request::create('/blog');
        $stack   = new RequestStack();
        $stack->push($request);

        $resolver = $this->createMock(BlogLocalesLocaleResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with($request)
            ->willReturn('en');

        $catalog = $this->createCatalog(
            requestStack: $stack,
            localeResolver: $resolver,
        );

        self::assertSame('en', $catalog->locale());
    }

    #[Test]
    public function itExposesSettingsProviderAccessors(): void
    {
        $provider = $this->createMock(BlogSettingsProvider::class);
        $provider->expects(self::once())->method('listingMode')->willReturn(BlogListingMode::Infinite);
        $provider->expects(self::once())->method('perPage')->willReturn(9);
        $provider->expects(self::once())->method('masonryStrategy')->willReturn(BlogMasonryStrategy::Grid);
        $provider->expects(self::once())->method('masonryColumns')->willReturn([
            'mobile'  => 1,
            'tablet'  => 2,
            'desktop' => 3,
        ]);

        $catalog = $this->createCatalog(blogSettingsProvider: $provider);

        self::assertSame($provider, $catalog->blogSettings());
        self::assertSame('infinite', $catalog->blogListingMode());
        self::assertSame(9, $catalog->blogPerPage());
        self::assertSame('grid', $catalog->blogMasonryStrategy());
        self::assertSame(['mobile' => 1, 'tablet' => 2, 'desktop' => 3], $catalog->blogMasonryColumns());
    }

    #[Test]
    public function itReturnsPublishedArticlesForResolvedLocale(): void
    {
        $articles = [['slug' => 'hello-world']];

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::once())
            ->method('fetchPublishedListData')
            ->with('es')
            ->willReturn($articles);

        $catalog = $this->createCatalog(blogArticleRepository: $articleRepository);

        self::assertSame($articles, $catalog->blogArticles());
    }

    #[Test]
    public function itBuildsPaginatedArticlesAndClampsInvalidInput(): void
    {
        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::once())
            ->method('fetchPublishedPaginatedData')
            ->with(1, 1, 'es', null, null)
            ->willReturn([
                'items' => [],
                'total' => 0,
            ]);

        $catalog = $this->createCatalog(blogArticleRepository: $articleRepository);
        $result  = $catalog->blogArticlesPage(-5, 0, '   ', '   ');

        self::assertSame([], $result['articles']);
        self::assertSame([
            'page'        => 1,
            'per_page'    => 1,
            'total'       => 0,
            'total_pages' => 0,
        ], $result['pagination']);
        self::assertSame([
            'q'   => '',
            'tag' => '',
        ], $result['filters']);
    }

    #[Test]
    public function itSlicesBlogTags(): void
    {
        $tags = [
            ['slug' => 'php', 'name' => 'PHP', 'count' => 8],
            ['slug' => 'symfony', 'name' => 'Symfony', 'count' => 6],
            ['slug' => 'ai', 'name' => 'AI', 'count' => 4],
        ];

        $tagRepository = $this->createMock(BlogTagRepository::class);
        $tagRepository->expects(self::once())
            ->method('findPublishedTagSummaries')
            ->with('es')
            ->willReturn($tags);

        $catalog = $this->createCatalog(blogTagRepository: $tagRepository);

        self::assertSame(array_slice($tags, 0, 2), $catalog->blogTags(2));
    }

    #[Test]
    public function itBuildsSidebarFromPublishedTagSummariesWhenNoFiltersAreActive(): void
    {
        $relatedTags = [
            ['slug' => 'php', 'name' => 'PHP', 'count' => 8],
            ['slug' => 'symfony', 'name' => 'Symfony', 'count' => 6],
        ];
        $latest = [['slug' => 'hello-world']];

        $provider = $this->createMock(BlogSettingsProvider::class);
        $provider->expects(self::once())->method('indexLatestLimit')->willReturn(3);
        $provider->expects(self::once())->method('indexAsideTagsLimit')->willReturn(1);

        $tagRepository = $this->createMock(BlogTagRepository::class);
        $tagRepository->expects(self::once())
            ->method('findPublishedTagSummaries')
            ->with('es')
            ->willReturn($relatedTags);

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::never())->method('fetchTagSummariesMatchingFilters');
        $articleRepository->expects(self::once())
            ->method('fetchLatestMatchingFilters')
            ->with('es', null, null, 3)
            ->willReturn($latest);

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogTagRepository: $tagRepository,
            blogSettingsProvider: $provider,
        );

        $sidebar = $catalog->blogSidebar();

        self::assertSame($latest, $sidebar['latest']);
        self::assertSame(array_slice($relatedTags, 0, 1), $sidebar['related_tags']);
    }

    #[Test]
    public function itKeepsAllSidebarTagsWhenConfiguredLimitIsZero(): void
    {
        $relatedTags = [
            ['slug' => 'php', 'name' => 'PHP', 'count' => 8],
            ['slug' => 'symfony', 'name' => 'Symfony', 'count' => 6],
        ];
        $latest = [['slug' => 'hello-world']];

        $provider = $this->createMock(BlogSettingsProvider::class);
        $provider->expects(self::once())->method('indexLatestLimit')->willReturn(3);
        $provider->expects(self::once())->method('indexAsideTagsLimit')->willReturn(0);

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::once())
            ->method('fetchTagSummariesMatchingFilters')
            ->with('es', 'term', 'php')
            ->willReturn($relatedTags);
        $articleRepository->expects(self::once())
            ->method('fetchLatestMatchingFilters')
            ->with('es', 'term', 'php', 3)
            ->willReturn($latest);

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogSettingsProvider: $provider,
        );

        $sidebar = $catalog->blogSidebar(' term ', ' php ');

        self::assertSame($latest, $sidebar['latest']);
        self::assertSame($relatedTags, $sidebar['related_tags']);
    }

    #[Test]
    public function itReturnsEmptyArticleSidebarForInvalidArticleId(): void
    {
        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::never())->method('fetchRelatedBySharedTags');

        $catalog = $this->createCatalog(blogArticleRepository: $articleRepository);

        self::assertSame([
            'related'   => [],
            'tags'      => [],
            'resources' => [],
        ], $catalog->blogArticleSidebar(0));
    }

    #[Test]
    public function itBuildsArticleSidebarForValidArticleId(): void
    {
        $related   = [['slug' => 'related-post']];
        $tags      = [['slug' => 'php', 'name' => 'PHP', 'count' => 4]];
        $resources = [42 => [['id' => 9, 'title' => 'Guide', 'image' => '/guide.png', 'position' => 1]]];

        $provider = $this->createMock(BlogSettingsProvider::class);
        $provider->expects(self::once())->method('relatedLimit')->willReturn(4);

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::once())
            ->method('fetchRelatedBySharedTags')
            ->with(42, 'es', 4)
            ->willReturn($related);
        $articleRepository->expects(self::once())
            ->method('fetchArticleTagSummaries')
            ->with(42, 'es')
            ->willReturn($tags);
        $articleRepository->expects(self::once())
            ->method('fetchResourcesByArticleIds')
            ->with([42])
            ->willReturn($resources);

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogSettingsProvider: $provider,
        );

        self::assertSame([
            'related'   => $related,
            'tags'      => $tags,
            'resources' => $resources[42],
        ], $catalog->blogArticleSidebar(42));
    }

    #[Test]
    public function itReturnsNullWhenArticleSlugIsMissing(): void
    {
        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::once())
            ->method('fetchPublishedDetailBySlug')
            ->with('missing-post', 'es')
            ->willReturn(null);

        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->expects(self::never())->method('localizeHashtagLinks');

        $enhancer = $this->createMock(BlogArticleBodyEnhancer::class);
        $enhancer->expects(self::never())->method('enhance');

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogHashtagProcessor: $processor,
            blogArticleBodyEnhancer: $enhancer,
        );

        self::assertNull($catalog->blogArticleBySlug('missing-post'));
    }

    #[Test]
    public function itSkipsBodyEnhancementWhenArticleBodyIsEmpty(): void
    {
        $article = [
            'slug'  => 'empty-body',
            'body'  => '',
            'title' => 'Empty body',
        ];

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::once())
            ->method('fetchPublishedDetailBySlug')
            ->with('empty-body', 'es')
            ->willReturn($article);

        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->expects(self::never())->method('localizeHashtagLinks');

        $enhancer = $this->createMock(BlogArticleBodyEnhancer::class);
        $enhancer->expects(self::never())->method('enhance');

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogHashtagProcessor: $processor,
            blogArticleBodyEnhancer: $enhancer,
        );

        self::assertSame($article + ['linkedin_url' => null], $catalog->blogArticleBySlug('empty-body'));
    }

    #[Test]
    public function itLocalizesAndEnhancesArticleBodyWhenPresent(): void
    {
        $article = [
            'slug'  => 'body-post',
            'body'  => '<p>#PHP</p>',
            'title' => 'Body post',
        ];

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->expects(self::once())
            ->method('fetchPublishedDetailBySlug')
            ->with('body-post', 'es')
            ->willReturn($article);

        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->expects(self::once())
            ->method('localizeHashtagLinks')
            ->with('<p>#PHP</p>')
            ->willReturn('<p><a>#PHP</a></p>');

        $enhancer = $this->createMock(BlogArticleBodyEnhancer::class);
        $enhancer->expects(self::once())
            ->method('enhance')
            ->with('<p><a>#PHP</a></p>')
            ->willReturn('<div>enhanced</div>');

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogHashtagProcessor: $processor,
            blogArticleBodyEnhancer: $enhancer,
        );

        self::assertSame([
            'slug'         => 'body-post',
            'body'         => '<div>enhanced</div>',
            'title'        => 'Body post',
            'linkedin_url' => null,
        ], $catalog->blogArticleBySlug('body-post'));
    }

    #[Test]
    public function itSanitizesTheArticleBodyLastWhenProtectionIsConfigured(): void
    {
        $article = [
            'slug'  => 'body-post',
            'body'  => '<p>Hi</p>',
            'title' => 'Body post',
        ];

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->method('fetchPublishedDetailBySlug')->willReturn($article);

        // The rewrite passes see the stored body; their output (here: markup they introduce) is sanitized afterwards.
        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->expects(self::once())->method('localizeHashtagLinks')->with('<p>Hi</p>')->willReturn('<p>Hi</p><script>x</script>');

        $enhancer = $this->createMock(BlogArticleBodyEnhancer::class);
        $enhancer->expects(self::once())->method('enhance')->with('<p>Hi</p><script>x</script>')->willReturn('<p onclick="x()">Hi</p><script>x</script>');

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogHashtagProcessor: $processor,
            blogArticleBodyEnhancer: $enhancer,
            blogProtection: BlogProtectionTestFactory::create(['htmlStrategy' => HtmlSanitizeStrategy::Allowlist]),
        );

        self::assertSame('<p>Hi</p>', $catalog->blogArticleBySlug('body-post')['body']);
    }

    /**
     * SEC-004 M1 PoC: the old regex hashtag rewrite cut the single-quoted `title` attribute (libxml writes single
     * quotes when the value contains `"`) after the allowlist ran, so `' tabindex=1 autofocus onfocus=…` became live
     * attributes for every visitor.
     */
    #[Test]
    public function theHashtagRewritePayloadNeverYieldsEventHandlers(): void
    {
        $payload = '<a class="blog-hashtag" title=\'a href="b\' href="https://e.test">#tag</a>'
            . '<p>\' tabindex=1 autofocus onfocus=alert(document.domain) x=</p>';

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->method('fetchPublishedDetailBySlug')->willReturn(['slug' => 'xss', 'body' => $payload]);

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params): string => '/blog?' . http_build_query($params));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('es');

        foreach ([null, BlogProtectionTestFactory::create(['htmlStrategy' => HtmlSanitizeStrategy::Allowlist])] as $protection) {
            $catalog = $this->createCatalog(
                blogArticleRepository: $articleRepository,
                blogHashtagProcessor: new BlogHashtagProcessor($translator, $urls),
                blogArticleBodyEnhancer: new BlogArticleBodyEnhancer(),
                blogProtection: $protection,
            );

            $body = (string) $catalog->blogArticleBySlug('xss')['body'];
            $doc  = HTMLDocument::createFromString('<!doctype html><body>' . $body, LIBXML_NOERROR);
            foreach ($doc->querySelectorAll('*') as $element) {
                foreach ($element->attributes as $attribute) {
                    self::assertDoesNotMatchRegularExpression('/^(on|autofocus$|tabindex$)/i', $attribute->name, $body);
                }
            }
            self::assertNotNull($doc->querySelector('a.blog-hashtag[href^="/blog?"]'), $body);
            self::assertStringContainsString('onfocus=alert', $doc->body?->textContent ?? '', 'the payload stays text');
        }
    }

    #[Test]
    public function itDropsNonHttpLinkedinUrls(): void
    {
        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $articleRepository->method('fetchPublishedDetailBySlug')->willReturnOnConsecutiveCalls(
            ['slug' => 'a', 'body' => '', 'linkedin_url' => 'javascript:alert(1)'],
            ['slug' => 'b', 'body' => '', 'linkedin_url' => ' https://www.linkedin.com/posts/x '],
        );

        $catalog = $this->createCatalog(blogArticleRepository: $articleRepository);

        self::assertNull($catalog->blogArticleBySlug('a')['linkedin_url']);
        self::assertSame('https://www.linkedin.com/posts/x', $catalog->blogArticleBySlug('b')['linkedin_url']);
        foreach (['JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.test', 'https:/x', 'http://\\evil', ''] as $url) {
            self::assertNull(BlogCatalog::httpUrl($url), $url);
        }
        self::assertNull(BlogCatalog::httpUrl(42));
    }

    private function createCatalog(
        ?RequestStack $requestStack = null,
        ?BlogArticleRepository $blogArticleRepository = null,
        ?BlogTagRepository $blogTagRepository = null,
        ?BlogSettingsProvider $blogSettingsProvider = null,
        ?BlogHashtagProcessor $blogHashtagProcessor = null,
        ?BlogArticleBodyEnhancer $blogArticleBodyEnhancer = null,
        ?BlogLocalesLocaleResolver $localeResolver = null,
        ?BlogProtection $blogProtection = null,
    ): BlogCatalog {
        $requestStack ??= new RequestStack();
        $blogArticleRepository ??= $this->createMock(BlogArticleRepository::class);
        $blogTagRepository ??= $this->createMock(BlogTagRepository::class);
        $blogSettingsProvider ??= $this->createMock(BlogSettingsProvider::class);
        $blogHashtagProcessor ??= $this->createMock(BlogHashtagProcessor::class);
        $blogArticleBodyEnhancer ??= $this->createMock(BlogArticleBodyEnhancer::class);
        $localeResolver ??= $this->createMock(BlogLocalesLocaleResolver::class);
        $localeResolver->method('resolve')->willReturn('es');

        return new BlogCatalog(
            $requestStack,
            $blogArticleRepository,
            $blogTagRepository,
            $blogSettingsProvider,
            $blogHashtagProcessor,
            $blogArticleBodyEnhancer,
            $localeResolver,
            $blogProtection,
        );
    }
}
