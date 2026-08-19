<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

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

use function array_slice;

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

        self::assertSame($article, $catalog->blogArticleBySlug('empty-body'));
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
            'slug'  => 'body-post',
            'body'  => '<div>enhanced</div>',
            'title' => 'Body post',
        ], $catalog->blogArticleBySlug('body-post'));
    }

    #[Test]
    public function itSanitizesArticleBodyBeforeHashtagsWhenProtectionIsConfigured(): void
    {
        $article = [
            'slug'  => 'body-post',
            'body'  => '<p>Hi</p><script>x</script>',
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
            ->with(self::callback(static fn (string $html): bool => !str_contains($html, 'script')))
            ->willReturn('<p>Hi</p>');

        $enhancer = $this->createMock(BlogArticleBodyEnhancer::class);
        $enhancer->expects(self::once())
            ->method('enhance')
            ->with('<p>Hi</p>')
            ->willReturn('<p>Hi</p>');

        $catalog = $this->createCatalog(
            blogArticleRepository: $articleRepository,
            blogHashtagProcessor: $processor,
            blogArticleBodyEnhancer: $enhancer,
            blogProtection: BlogProtectionTestFactory::create(['htmlStrategy' => HtmlSanitizeStrategy::Allowlist]),
        );

        self::assertSame('<p>Hi</p>', $catalog->blogArticleBySlug('body-post')['body']);
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
