<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller;

use Nowo\BlogKitBundle\Controller\BlogPublicController;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Meta\BlogBrandNameProviderInterface;
use Nowo\BlogKitBundle\Meta\BlogIndexMetaProviderInterface;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Service\BlogCatalog;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Tests\Support\ControllerTestHelper;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function count;

final class BlogPublicControllerTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function indexRendersFullPageWithFallbackMetaWhenProviderIsMissing(): void
    {
        $request  = Request::create('/blog?page=2&q=Symfony&tag=php', 'GET');
        $settings = $this->createSettingsProvider(
            boolMap: [
                ['show_card_image', true, true],
                ['show_card_excerpt', true, false],
                ['show_card_tags', true, true],
            ],
            all: ['show_card_image' => true],
            asideSlots: ['left' => ['search'], 'right' => ['latest', 'tags']],
        );

        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->expects(self::once())->method('blogSettings')->willReturn($settings);
        $catalog->expects(self::once())->method('blogListingMode')->willReturn('paginated');
        $catalog->expects(self::once())->method('blogPerPage')->willReturn(6);
        $catalog->expects(self::once())->method('blogMasonryStrategy')->willReturn('grid');
        $catalog->expects(self::once())->method('blogMasonryColumns')->willReturn([
            'mobile'  => 1,
            'tablet'  => 2,
            'desktop' => 3,
        ]);
        $catalog->expects(self::once())
            ->method('blogArticlesPage')
            ->with(2, 6, 'Symfony', 'php')
            ->willReturn([
                'articles'   => [['id' => 1, 'title' => 'Article']],
                'pagination' => ['page' => 2, 'per_page' => 6, 'total' => 8, 'total_pages' => 2],
                'filters'    => ['q' => 'Symfony', 'tag' => 'php'],
            ]);
        $catalog->expects(self::once())->method('blogSidebar')->with('Symfony', 'php')->willReturn([
            'latest'       => [['slug' => 'latest']],
            'related_tags' => [['slug' => 'php', 'name' => 'PHP', 'count' => 4]],
        ]);
        $catalog->expects(self::once())->method('blogTags')->willReturn([
            ['slug' => 'php', 'name' => 'PHP', 'count' => 4],
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))
            ->method('trans')
            ->willReturnMap([
                ['page.blog.heading', [], 'NowoBlogKitBundle', null, 'Blog'],
                ['page.blog.intro', [], 'NowoBlogKitBundle', null, 'Intro'],
            ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/public/index.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'Blog'
                    && $parameters['page_description'] === 'Intro'
                    && $parameters['pagination']['page'] === 2
                    && $parameters['filters'] === ['q' => 'Symfony', 'tag' => 'php']
                    && $parameters['card_options'] === [
                        'show_image'   => true,
                        'show_excerpt' => false,
                        'show_tags'    => true,
                    ]
                    && $parameters['aside_left'] === ['search']
                    && $parameters['aside_right'] === ['latest', 'tags']
                    && $parameters['listing_mode'] === 'paginated'
                    && $parameters['masonry_strategy'] === 'grid'
                    && $parameters['masonry_columns'] === [
                        'mobile'  => 1,
                        'tablet'  => 2,
                        'desktop' => 3,
                    ]),
            )
            ->willReturn('index');

        $controller = $this->createController(
            request: $request,
            catalog: $catalog,
            translator: $translator,
            twig: $twig,
        );

        $response = $controller->index($request);

        self::assertSame('index', $response->getContent());
    }

    #[Test]
    public function indexRendersPartialItemsWhenRequested(): void
    {
        $request  = Request::create('/blog?partial=1', 'GET');
        $settings = $this->createSettingsProvider(
            boolMap: [
                ['show_card_image', true, true],
                ['show_card_excerpt', true, true],
                ['show_card_tags', true, false],
            ],
        );

        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->method('blogSettings')->willReturn($settings);
        $catalog->method('blogListingMode')->willReturn('paginated');
        $catalog->method('blogPerPage')->willReturn(6);
        $catalog->expects(self::once())
            ->method('blogArticlesPage')
            ->with(1, 6, null, null)
            ->willReturn([
                'articles'   => [['id' => 1]],
                'pagination' => ['page' => 1, 'per_page' => 6, 'total' => 1, 'total_pages' => 1],
                'filters'    => ['q' => '', 'tag' => ''],
            ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/public/_items.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['articles'] === [['id' => 1]]
                    && $parameters['filters'] === ['q' => '', 'tag' => '']
                    && $parameters['card_options'] === [
                        'show_image'   => true,
                        'show_excerpt' => true,
                        'show_tags'    => false,
                    ]),
            )
            ->willReturn('partial');

        $controller = $this->createController(
            request: $request,
            catalog: $catalog,
            twig: $twig,
        );

        $response = $controller->index($request);

        self::assertSame('partial', $response->getContent());
    }

    #[Test]
    public function indexThrowsNotFoundWhenPageOverflows(): void
    {
        $request  = Request::create('/blog?page=4', 'GET');
        $settings = $this->createSettingsProvider();

        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->method('blogSettings')->willReturn($settings);
        $catalog->method('blogListingMode')->willReturn('paginated');
        $catalog->method('blogPerPage')->willReturn(6);
        $catalog->expects(self::once())
            ->method('blogArticlesPage')
            ->with(4, 6, null, null)
            ->willReturn([
                'articles'   => [],
                'pagination' => ['page' => 4, 'per_page' => 6, 'total' => 8, 'total_pages' => 2],
                'filters'    => ['q' => '', 'tag' => ''],
            ]);

        $controller = $this->createController(
            request: $request,
            catalog: $catalog,
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Blog page not found.');

        $controller->index($request);
    }

    #[Test]
    public function showThrowsNotFoundWhenArticleBodyIsEmpty(): void
    {
        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->expects(self::once())
            ->method('blogArticleBySlug')
            ->with('missing-post')
            ->willReturn([
                'id'    => 9,
                'title' => 'Missing',
                'body'  => '',
            ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('blog.article.not_found', [], 'NowoBlogKitBundle')
            ->willReturn('Article not found');

        $controller = $this->createController(
            catalog: $catalog,
            translator: $translator,
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Article not found');

        $controller->show('missing-post');
    }

    #[Test]
    public function showUsesEmptyCommentsAndSidebarResourcesWhenArticleEntityIsMissing(): void
    {
        $articleData = [
            'id'               => 9,
            'title'            => 'Article title',
            'excerpt'          => 'Article excerpt',
            'body'             => '<p>Body</p>',
            'meta_title'       => 'SEO title',
            'meta_description' => '',
            'linkedin_url'     => '',
            'resources'        => [['type' => 'pdf', 'id' => 1, 'title' => 'Fallback', 'image' => null, 'url' => null, 'position' => 2]],
        ];
        $sidebarResources = [['type' => 'video', 'id' => 2, 'title' => 'Primary', 'image' => null, 'url' => null, 'position' => 1]];
        $settings         = $this->createSettingsProvider(
            boolMap: [
                ['resources_include_linkedin', true, false],
                ['show_comments', true, true],
                ['show_share', true, false],
                ['show_source_link', true, false],
            ],
            all: ['show_comments' => true],
            asideSlots: ['left' => [], 'right' => ['resources']],
            heroMode: BlogHeroImageMode::Contain,
        );

        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->expects(self::once())->method('blogArticleBySlug')->with('article-title')->willReturn($articleData);
        $catalog->expects(self::once())->method('blogSettings')->willReturn($settings);
        $catalog->expects(self::once())->method('blogArticleSidebar')->with(9)->willReturn([
            'related'   => [],
            'tags'      => [],
            'resources' => $sidebarResources,
        ]);

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'article-title', 'published' => true])
            ->willReturn(null);

        $commentManager = $this->createMock(BlogCommentManager::class);
        $commentManager->expects(self::never())->method('approvedCommentsForArticle');

        $accessChecker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $accessChecker->expects(self::once())->method('canModerate')->willReturn(false);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/public/show.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'SEO title'
                    && $parameters['comments'] === []
                    && $parameters['reply_forms'] === []
                    && $parameters['sidebar_resources'] === $sidebarResources),
            )
            ->willReturn('show-seo');

        $controller = $this->createController(
            catalog: $catalog,
            repository: $repository,
            commentManager: $commentManager,
            accessChecker: $accessChecker,
            twig: $twig,
        );

        $response = $controller->show('article-title');

        self::assertSame('show-seo', $response->getContent());
    }

    #[Test]
    public function showRendersCommentsModerationAndInjectsLinkedinResource(): void
    {
        $articleData = [
            'id'               => 9,
            'title'            => 'Article title',
            'excerpt'          => 'Article excerpt',
            'body'             => '<p>Body</p>',
            'meta_title'       => '',
            'meta_description' => '',
            'linkedin_url'     => 'https://linkedin.example/post',
            'resources'        => [],
        ];
        $articleEntity = $this->createArticle(9, 'article-title', true);
        $commentA      = $this->createComment(10, $articleEntity);
        $commentB      = $this->createComment(11, $articleEntity);

        $settings = $this->createSettingsProvider(
            boolMap: [
                ['resources_include_linkedin', true, true],
                ['show_comments', true, true],
                ['show_share', true, true],
                ['show_source_link', true, true],
            ],
            all: ['show_comments' => true],
            asideSlots: ['left' => ['search'], 'right' => ['related', 'tags', 'resources']],
            heroMode: BlogHeroImageMode::Contain,
        );

        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->expects(self::once())->method('blogArticleBySlug')->with('article-title')->willReturn($articleData);
        $catalog->expects(self::once())->method('blogSettings')->willReturn($settings);
        $catalog->expects(self::once())->method('blogArticleSidebar')->with(9)->willReturn([
            'related'   => [['id' => 15]],
            'tags'      => [['slug' => 'php', 'name' => 'PHP', 'count' => 1]],
            'resources' => [],
        ]);

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'article-title', 'published' => true])
            ->willReturn($articleEntity);

        $commentManager = $this->createMock(BlogCommentManager::class);
        $commentManager->expects(self::once())
            ->method('approvedCommentsForArticle')
            ->with($articleEntity)
            ->willReturn([$commentA, $commentB]);

        $accessChecker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $accessChecker->expects(self::once())->method('canModerate')->willReturn(true);

        $brand = $this->createMock(BlogBrandNameProviderInterface::class);
        $brand->expects(self::once())->method('brandName')->willReturn('Nowo');

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/public/show.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'Article title | Nowo'
                    && $parameters['article'] === $articleData
                    && $parameters['comments'] === [$commentA, $commentB]
                    && $parameters['can_moderate_comments'] === true
                    && count($parameters['reply_forms']) === 2
                    && $parameters['sidebar_resources'][0]['type'] === 'linkedin'
                    && $parameters['sidebar_resources'][0]['url'] === 'https://linkedin.example/post'
                    && $parameters['show_comments'] === true),
            )
            ->willReturn('show');

        $controller = $this->createController(
            catalog: $catalog,
            repository: $repository,
            commentManager: $commentManager,
            accessChecker: $accessChecker,
            brandNameProvider: $brand,
            twig: $twig,
        );

        $response = $controller->show('article-title');

        self::assertSame('show', $response->getContent());
    }

    #[Test]
    public function showHidesCommentsWhenDisabled(): void
    {
        $articleData = [
            'id'               => 9,
            'title'            => 'Article title',
            'excerpt'          => 'Article excerpt',
            'body'             => '<p>Body</p>',
            'meta_title'       => '',
            'meta_description' => '',
            'linkedin_url'     => '',
            'resources'        => [],
        ];
        $articleEntity = $this->createArticle(9, 'article-title', true);
        $comment       = $this->createComment(10, $articleEntity);

        $settings = $this->createSettingsProvider(
            boolMap: [
                ['resources_include_linkedin', true, false],
                ['show_comments', true, false],
                ['show_share', true, false],
                ['show_source_link', true, true],
            ],
            all: ['show_comments' => false],
            asideSlots: ['left' => [], 'right' => ['related']],
            heroMode: BlogHeroImageMode::Cover,
        );

        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->method('blogArticleBySlug')->willReturn($articleData);
        $catalog->method('blogSettings')->willReturn($settings);
        $catalog->method('blogArticleSidebar')->willReturn([
            'related'   => [],
            'tags'      => [],
            'resources' => [],
        ]);

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->method('findOneBy')->willReturn($articleEntity);

        $commentManager = $this->createMock(BlogCommentManager::class);
        $commentManager->expects(self::once())->method('approvedCommentsForArticle')->with($articleEntity)->willReturn([$comment]);

        $accessChecker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $accessChecker->expects(self::once())->method('canModerate')->willReturn(false);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/public/show.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['comments'] === []
                    && $parameters['reply_forms'] === []
                    && $parameters['show_comments'] === false
                    && $parameters['show_share'] === false
                    && $parameters['hero_image_mode'] === 'cover'),
            )
            ->willReturn('show-disabled');

        $controller = $this->createController(
            catalog: $catalog,
            repository: $repository,
            commentManager: $commentManager,
            accessChecker: $accessChecker,
            twig: $twig,
        );

        $response = $controller->show('article-title');

        self::assertSame('show-disabled', $response->getContent());
    }

    #[Test]
    public function showSkipsModerationReplyFormForCommentsWithoutIdentifier(): void
    {
        $articleData = [
            'id'               => 9,
            'title'            => 'Article title',
            'excerpt'          => 'Article excerpt',
            'body'             => '<p>Body</p>',
            'meta_title'       => '',
            'meta_description' => '',
            'linkedin_url'     => '',
            'resources'        => [],
        ];
        $articleEntity    = $this->createArticle(9, 'article-title', true);
        $commentWithoutId = (new BlogComment())
            ->setArticle($articleEntity)
            ->setAuthorName('Pending')
            ->setBody('Comment')
            ->setStatus(BlogCommentStatus::Approved);
        $commentWithId = $this->createComment(11, $articleEntity);

        $settings = $this->createSettingsProvider(
            boolMap: [
                ['resources_include_linkedin', true, false],
                ['show_comments', true, true],
                ['show_share', true, true],
                ['show_source_link', true, true],
            ],
            all: ['show_comments' => true],
            asideSlots: ['left' => [], 'right' => []],
            heroMode: BlogHeroImageMode::Contain,
        );

        $catalog = $this->createMock(BlogCatalog::class);
        $catalog->method('blogArticleBySlug')->willReturn($articleData);
        $catalog->method('blogSettings')->willReturn($settings);
        $catalog->method('blogArticleSidebar')->willReturn([
            'related'   => [],
            'tags'      => [],
            'resources' => [],
        ]);

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->method('findOneBy')->willReturn($articleEntity);

        $commentManager = $this->createMock(BlogCommentManager::class);
        $commentManager->expects(self::once())
            ->method('approvedCommentsForArticle')
            ->with($articleEntity)
            ->willReturn([$commentWithoutId, $commentWithId]);

        $accessChecker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $accessChecker->expects(self::once())->method('canModerate')->willReturn(true);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/public/show.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['comments'] === [$commentWithoutId, $commentWithId]
                    && count($parameters['reply_forms']) === 1
                    && isset($parameters['reply_forms'][11])),
            )
            ->willReturn('show-skip-null-id');

        $controller = $this->createController(
            catalog: $catalog,
            repository: $repository,
            commentManager: $commentManager,
            accessChecker: $accessChecker,
            twig: $twig,
        );

        $response = $controller->show('article-title');

        self::assertSame('show-skip-null-id', $response->getContent());
    }

    private function createController(
        ?Request $request = null,
        ?BlogCatalog $catalog = null,
        ?BlogArticleRepository $repository = null,
        ?BlogCommentManager $commentManager = null,
        ?BlogKitAccessCheckerInterface $accessChecker = null,
        ?TranslatorInterface $translator = null,
        ?BlogIndexMetaProviderInterface $metaProvider = null,
        ?BlogBrandNameProviderInterface $brandNameProvider = null,
        ?Environment $twig = null,
        ?FormFactoryInterface $formFactory = null,
    ): BlogPublicController {
        $request ??= Request::create('/blog', 'GET');

        $controller = new BlogPublicController(
            $catalog ?? $this->createMock(BlogCatalog::class),
            $repository ?? $this->createMock(BlogArticleRepository::class),
            $commentManager ?? $this->createMock(BlogCommentManager::class),
            $accessChecker ?? $this->createMock(BlogKitAccessCheckerInterface::class),
            $translator ?? $this->createMock(TranslatorInterface::class),
            $metaProvider,
            $brandNameProvider,
        );

        ControllerTestHelper::bind($controller, $request, array_filter([
            'twig'         => $twig,
            'form.factory' => $formFactory ?? ControllerTestHelper::createFormFactory(),
        ]));

        return $controller;
    }

    private function createSettingsProvider(
        array $boolMap = [],
        array $all = [],
        array $asideSlots = ['left' => [], 'right' => []],
        BlogHeroImageMode $heroMode = BlogHeroImageMode::Contain,
    ): BlogSettingsProvider {
        $settings = $this->createMock(BlogSettingsProvider::class);
        $settings->method('bool')->willReturnCallback(
            static function (string $key, bool $default = true) use ($boolMap): bool {
                foreach ($boolMap as $row) {
                    if (($row[0] ?? null) === $key) {
                        return (bool) ($row[2] ?? $default);
                    }
                }

                return $default;
            },
        );
        $settings->method('all')->willReturn($all);
        $settings->method('asideSlots')->willReturn($asideSlots);
        $settings->method('heroImageMode')->willReturn($heroMode);

        return $settings;
    }

    private function createArticle(int $id, string $slug, bool $published): BlogArticle
    {
        $article = (new BlogArticle())
            ->setSlug($slug)
            ->setPublished($published);

        $reflection = new ReflectionProperty(BlogArticle::class, 'id');
        $reflection->setValue($article, $id);

        return $article;
    }

    private function createComment(int $id, BlogArticle $article): BlogComment
    {
        $comment = (new BlogComment())
            ->setArticle($article)
            ->setAuthorName('Author')
            ->setBody('Comment')
            ->setStatus(BlogCommentStatus::Approved);

        $reflection = new ReflectionProperty(BlogComment::class, 'id');
        $reflection->setValue($comment, $id);

        return $comment;
    }
}
