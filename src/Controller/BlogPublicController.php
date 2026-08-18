<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Form\BlogPublicSearchType;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Form\StaffBlogCommentReplyType;
use Nowo\BlogKitBundle\Meta\BlogBrandNameProviderInterface;
use Nowo\BlogKitBundle\Meta\BlogIndexMetaProviderInterface;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Service\BlogCatalog;
use Nowo\BlogKitBundle\Service\BlogCommentManager;
use Nowo\RoutingKitBundle\Attribute\Routable;
use Nowo\RoutingKitBundle\Attribute\RouteParam;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public blog index and article detail.
 */
final class BlogPublicController extends AbstractController
{
    public function __construct(
        private readonly BlogCatalog $blogCatalog,
        private readonly BlogArticleRepository $blogArticleRepository,
        private readonly BlogCommentManager $blogCommentManager,
        private readonly BlogKitAccessCheckerInterface $accessChecker,
        private readonly TranslatorInterface $translator,
        private readonly ?BlogIndexMetaProviderInterface $blogIndexMetaProvider = null,
        private readonly ?BlogBrandNameProviderInterface $blogBrandNameProvider = null,
    ) {
    }

    #[Route('/blog', name: 'blog_index', methods: ['GET'])]
    #[Routable(name: 'blog_index')]
    public function index(Request $request): Response
    {
        $page            = max(1, $request->query->getInt('page', 1));
        $search          = trim((string) $request->query->get('q', ''));
        $tag             = trim((string) $request->query->get('tag', ''));
        $searchForm      = $this->createPublicSearchForm($request, $search, $tag);
        $asideSearchForm = $this->createPublicSearchForm($request, $search, $tag);
        $blogSettings    = $this->blogCatalog->blogSettings();
        $listingMode     = $this->blogCatalog->blogListingMode();
        $perPage         = $this->blogCatalog->blogPerPage();
        $blog            = $this->blogCatalog->blogArticlesPage(
            $page,
            $perPage,
            $search !== '' ? $search : null,
            $tag !== '' ? $tag : null,
        );

        if (
            $page > 1
            && $blog['pagination']['total_pages'] > 0
            && $page > $blog['pagination']['total_pages']
        ) {
            throw new NotFoundHttpException('Blog page not found.');
        }

        $cardOptions = [
            'show_image'   => $blogSettings->bool('show_card_image'),
            'show_excerpt' => $blogSettings->bool('show_card_excerpt'),
            'show_tags'    => $blogSettings->bool('show_card_tags'),
        ];

        if ($request->query->getBoolean('partial')) {
            return $this->render('@NowoBlogKitBundle/public/_items.html.twig', [
                'articles'     => $blog['articles'],
                'filters'      => $blog['filters'],
                'card_options' => $cardOptions,
            ]);
        }

        $blogMeta = $this->blogIndexMetaProvider?->meta() ?? [
            'title'       => $this->translator->trans('page.blog.heading', [], 'NowoBlogKitBundle'),
            'description' => $this->translator->trans('page.blog.intro', [], 'NowoBlogKitBundle'),
        ];
        $sidebar = $this->blogCatalog->blogSidebar(
            $search !== '' ? $search : null,
            $tag !== '' ? $tag : null,
        );
        $asideSlots = $blogSettings->asideSlots([
            'search' => 'index_aside_search',
            'latest' => 'index_aside_latest',
            'tags'   => 'index_aside_tags',
        ]);

        return $this->render('@NowoBlogKitBundle/public/index.html.twig', [
            'page_title'        => $blogMeta['title'],
            'page_description'  => $blogMeta['description'],
            'seo_page_key'      => $blogMeta['page_key'] ?? '',
            'current_route'     => 'blog_index',
            'articles'          => $blog['articles'],
            'pagination'        => $blog['pagination'],
            'filters'           => $blog['filters'],
            'search_form'       => $searchForm,
            'aside_search_form' => $asideSearchForm,
            'listing_mode'      => $listingMode,
            'blog_settings'     => $blogSettings->all(),
            'card_options'      => $cardOptions,
            'tags'              => $this->blogCatalog->blogTags(),
            'sidebar_latest'    => $sidebar['latest'],
            'sidebar_tags'      => $sidebar['related_tags'],
            'aside_left'        => $asideSlots['left'],
            'aside_right'       => $asideSlots['right'],
            'breadcrumbs'       => [
                ['route' => 'home', 'label_key' => 'nav.home'],
                ['route' => 'blog_index', 'label_key' => 'nav.blog'],
            ],
        ]);
    }

    #[Route('/blog/{slug}', name: 'blog_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[Routable(name: 'blog_show', params: [
        new RouteParam('slug', required: true, requirement: '[a-z0-9-]+'),
    ])]
    public function show(string $slug): Response
    {
        $article = $this->blogCatalog->blogArticleBySlug($slug);

        if ($article === null || '' === ($article['body'] ?? '')) {
            throw new NotFoundHttpException($this->translator->trans('blog.article.not_found', [], 'NowoBlogKitBundle'));
        }

        $articleEntity = $this->blogArticleRepository->findOneBy(['slug' => $slug, 'published' => true]);
        $comments      = $articleEntity instanceof BlogArticle
            ? $this->blogCommentManager->approvedCommentsForArticle($articleEntity)
            : [];
        $commentForm = $this->createForm(PublicBlogCommentType::class, null, [
            'action' => $this->generateUrl('blog_comment_create', ['slug' => $slug]),
        ]);

        $metaTitle        = trim((string) ($article['meta_title'] ?? ''));
        $metaDescription  = trim((string) ($article['meta_description'] ?? ''));
        $brandName        = $this->blogBrandNameProvider?->brandName() ?? '';
        $articleId        = (int) ($article['id'] ?? 0);
        $blogSettings     = $this->blogCatalog->blogSettings();
        $sidebar          = $this->blogCatalog->blogArticleSidebar($articleId);
        $sidebarResources = $sidebar['resources'] !== []
            ? $sidebar['resources']
            : ($article['resources'] ?? []);
        $linkedinUrl = trim((string) ($article['linkedin_url'] ?? ''));

        if ($linkedinUrl !== '' && $blogSettings->bool('resources_include_linkedin')) {
            array_unshift($sidebarResources, [
                'type'     => 'linkedin',
                'id'       => null,
                'title'    => null,
                'image'    => null,
                'url'      => $linkedinUrl,
                'position' => -1,
            ]);
        }

        $asideSlots = $blogSettings->asideSlots([
            'search'    => 'show_aside_search',
            'related'   => 'show_aside_related',
            'tags'      => 'show_aside_article_tags',
            'resources' => 'show_aside_resources',
        ]);

        $commentsEnabled = $blogSettings->bool('show_comments');

        if (!$commentsEnabled) {
            $comments = [];
        }

        $canModerate = $this->accessChecker->canModerate();
        /** @var array<int, FormView> $replyForms */
        $replyForms = [];
        if ($canModerate) {
            foreach ($comments as $comment) {
                $commentId = $comment->getId();
                if ($commentId === null) {
                    continue;
                }
                $replyForms[$commentId] = $this->createForm(StaffBlogCommentReplyType::class, null, [
                    'action' => $this->generateUrl('blog_comment_staff_reply', ['id' => $commentId]),
                    'method' => 'POST',
                ])->createView();
            }
        }

        $title = $metaTitle !== ''
            ? $metaTitle
            : ($brandName !== '' ? $article['title'] . ' | ' . $brandName : (string) $article['title']);

        return $this->render('@NowoBlogKitBundle/public/show.html.twig', [
            'page_title'            => $title,
            'page_description'      => $metaDescription !== '' ? $metaDescription : ($article['excerpt'] ?: $article['title']),
            'current_route'         => 'blog_show',
            'article'               => $article,
            'article_id'            => $articleId,
            'comments'              => $comments,
            'comment_form'          => $commentForm,
            'reply_forms'           => $replyForms,
            'can_moderate_comments' => $canModerate,
            'filters'               => ['q' => '', 'tag' => ''],
            'search_form'           => $this->createPublicSearchForm(Request::create('/blog'), '', ''),
            'blog_settings'         => $blogSettings->all(),
            'sidebar_related'       => $sidebar['related'],
            'sidebar_tags'          => $sidebar['tags'],
            'sidebar_resources'     => $sidebarResources,
            'aside_left'            => $asideSlots['left'],
            'aside_right'           => $asideSlots['right'],
            'show_share'            => $blogSettings->bool('show_share'),
            'show_comments'         => $commentsEnabled,
            'show_source_link'      => $blogSettings->bool('show_source_link'),
            'hero_image_mode'       => $blogSettings->heroImageMode()->value,
            'breadcrumbs'           => [
                ['route' => 'home', 'label_key' => 'nav.home'],
                ['route' => 'blog_index', 'label_key' => 'nav.blog'],
                [
                    'route'  => 'blog_show',
                    'label'  => $article['title'],
                    'params' => ['slug' => $slug],
                ],
            ],
        ]);
    }

    /**
     * @return FormInterface<array{q?: string, tag?: string}>
     */
    private function createPublicSearchForm(Request $request, string $search, string $tag): FormInterface
    {
        $form = $this->createForm(BlogPublicSearchType::class, [
            'q'   => $search,
            'tag' => $tag,
        ], [
            'action' => $this->generateUrl('blog_index'),
            'method' => 'GET',
        ]);
        $form->handleRequest($request);

        return $form;
    }
}
