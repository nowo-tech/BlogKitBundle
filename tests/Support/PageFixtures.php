<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Form\BlogArticleFilterType;
use Nowo\BlogKitBundle\Form\BlogArticleInlineTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleResourceType;
use Nowo\BlogKitBundle\Form\BlogArticleTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleType;
use Nowo\BlogKitBundle\Form\BlogCommentFilterType;
use Nowo\BlogKitBundle\Form\BlogInlineModalType;
use Nowo\BlogKitBundle\Form\BlogPublicSearchType;
use Nowo\BlogKitBundle\Form\BlogSettingsType;
use Nowo\BlogKitBundle\Form\BlogTagFilterType;
use Nowo\BlogKitBundle\Form\BlogTagTranslationType;
use Nowo\BlogKitBundle\Form\BlogTagType;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Form\StaffBlogCommentReplyType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Ui\BlogKitAreaNav;
use Nowo\BlogKitBundle\Ui\BlogKitPageFactory;
use Nowo\BlogKitBundle\Ui\BlogKitPages;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\Extension\LocaleTabsExtension;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Symfony\Bridge\Doctrine\Form\DoctrineOrmExtension;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

use function dirname;
use function http_build_query;

use const CASE_LOWER;

/**
 * Page contexts of the REQ-UI-003 page contract test: the view models come from {@see BlogKitPageFactory} (the
 * service the controllers use), the FormViews from the real form types, the rest mirrors the controller variables.
 */
final class PageFixtures
{
    public const string LOCALE = 'en';

    private static ?TestManagerRegistry $registry = null;

    /**
     * @return array<string, mixed>
     */
    public static function articleIndex(bool $empty = false, ?BlogKitPageFactory $factory = null): array
    {
        $items       = $empty ? [] : [self::article(1, 'hello-world', true), self::article(2, 'draft-notes', false)];
        $filterForm  = self::filterForm(BlogArticleFilterType::class);
        $pagination  = ['items' => $items, 'page' => 1, 'per_page' => 20, 'total' => $empty ? 0 : 42, 'total_pages' => $empty ? 1 : 3];
        $deleteForms = [];
        foreach ($items as $item) {
            $deleteForms[(int) $item->getId()] = self::csrfForm('/admin/blog/' . $item->getId() . '/delete', 'delete' . $item->getId());
        }
        $filters = $empty ? ['title' => 'nothing'] : [];

        return [
            'page'         => ($factory ?? self::factory())->articleIndex($items, $pagination, $filterForm, $filters, $deleteForms),
            'page_title'   => 'admin.articles.title',
            'items'        => $items,
            'pagination'   => $pagination,
            'filters'      => $filters,
            'filter_form'  => $filterForm,
            'delete_forms' => $deleteForms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function articleForm(bool $edit = false): array
    {
        $article = self::article(1, 'hello-world', true);
        $form    = self::forms()->create(BlogArticleType::class, $article)->createView();
        $title   = $edit ? 'admin.articles.edit' : 'admin.articles.new';

        return [
            'page'       => self::factory()->articleForm($form, $title),
            'page_title' => $title,
            'form'       => $form,
        ] + ($edit ? ['article' => $article] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function tagIndex(bool $empty = false): array
    {
        $items       = $empty ? [] : [self::tag(1, 'php', 'PHP'), self::tag(2, 'symfony', 'Symfony')];
        $filterForm  = self::filterForm(BlogTagFilterType::class);
        $deleteForms = [];
        foreach ($items as $item) {
            $deleteForms[(int) $item->getId()] = self::csrfForm('/admin/blog/tags/' . $item->getId() . '/delete', 'delete' . $item->getId());
        }

        return [
            'page'           => self::factory()->tagIndex($items, $filterForm, [], $deleteForms),
            'page_title'     => 'admin.tags.title',
            'items'          => $items,
            'article_counts' => [1 => 3],
            'filters'        => [],
            'filter_form'    => $filterForm,
            'delete_forms'   => $deleteForms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tagForm(): array
    {
        $tag  = self::tag(1, 'php', 'PHP');
        $form = self::forms()->create(BlogTagType::class, $tag)->createView();

        return [
            'page'       => self::factory()->tagForm($form, 'admin.tags.edit'),
            'page_title' => 'admin.tags.edit',
            'form'       => $form,
            'tag'        => $tag,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function commentIndex(bool $empty = false): array
    {
        $article = self::article(1, 'hello-world', true);
        $items   = $empty ? [] : [
            self::comment(1, $article, BlogCommentStatus::Pending, 'Ada', 'Great article, thanks for sharing it.'),
            self::comment(2, $article, BlogCommentStatus::Approved, 'Linus', 'A second, approved comment.'),
        ];
        $filterForm  = self::filterForm(BlogCommentFilterType::class, ['status' => 'pending']);
        $replyForms  = [];
        $actionForms = [];
        foreach ($items as $item) {
            $id               = (int) $item->getId();
            $replyForms[$id]  = self::forms()->create(StaffBlogCommentReplyType::class, null, ['action' => '/admin/blog/comments/' . $id . '/reply?status=pending'])->createView();
            $actionForms[$id] = [
                'approve' => self::csrfForm('/admin/blog/comments/' . $id . '/approve?status=pending', 'approve' . $id),
                'reject'  => self::csrfForm('/admin/blog/comments/' . $id . '/reject?status=pending', 'reject' . $id),
                'delete'  => self::csrfForm('/admin/blog/comments/' . $id . '/delete?status=pending', 'delete' . $id),
            ];
        }

        return [
            'page'          => self::factory()->commentIndex($items, $filterForm, [], 'pending', $empty ? 0 : 1, $actionForms),
            'page_title'    => 'admin.comments.title',
            'items'         => $items,
            'filters'       => [],
            'filter'        => 'pending',
            'filter_form'   => $filterForm,
            'pending_count' => $empty ? 0 : 1,
            'reply_forms'   => $replyForms,
            'action_forms'  => $actionForms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(string $section): array
    {
        $form = self::forms()->create(BlogSettingsType::class, new BlogSettings(), [BlogSettingsType::OPTION_SECTION => $section])->createView();

        return [
            'page'         => self::factory()->settings($section, $form),
            'page_title'   => 'admin.settings.title',
            'form'         => $form,
            'edit_section' => $section,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function inlineModal(): array
    {
        $article = self::article(1, 'hello-world', true);

        return [
            'form'   => self::forms()->create(BlogInlineModalType::class, $article)->createView(),
            'locale' => 'es',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicIndex(bool $empty = false, ?BlogKitPageFactory $factory = null): array
    {
        $filters    = $empty ? ['q' => 'nothing', 'tag' => ''] : ['q' => '', 'tag' => ''];
        $pagination = ['page' => 1, 'per_page' => 2, 'total' => $empty ? 0 : 5, 'total_pages' => $empty ? 0 : 3];
        $tags       = [['slug' => 'php', 'name' => 'PHP', 'count' => 3], ['slug' => 'symfony', 'name' => 'Symfony', 'count' => 2]];

        $factory ??= self::factory();

        return [
            'header'            => $factory->publicIndexHeader(),
            'pager'             => $factory->publicPager($pagination, $filters),
            'page_title'        => 'Blog',
            'page_description'  => 'Articles and notes.',
            'seo_page_key'      => '',
            'current_route'     => 'blog_index',
            'articles'          => $empty ? [] : [self::articleData('hello-world', 'Hello world'), self::articleData('second-post', 'Second post', false)],
            'pagination'        => $pagination,
            'filters'           => $filters,
            'search_form'       => self::searchForm(),
            'aside_search_form' => self::searchForm(),
            'listing_mode'      => 'paginated',
            'masonry_strategy'  => 'masonry',
            'masonry_columns'   => ['mobile' => 1, 'tablet' => 2, 'desktop' => 2],
            'blog_settings'     => [],
            'card_options'      => ['show_image' => true, 'show_excerpt' => true, 'show_tags' => true],
            'tags'              => $tags,
            'sidebar_latest'    => [self::articleData('hello-world', 'Hello world')],
            'sidebar_tags'      => $tags,
            'aside_left'        => [],
            'aside_right'       => ['search', 'latest', 'tags'],
            'breadcrumbs'       => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicShow(): array
    {
        $article = self::articleData('hello-world', 'Hello world') + [
            'body'         => '<p>First paragraph of the article.</p>',
            'linkedin_url' => 'https://www.linkedin.com/posts/example',
            'resources'    => [],
        ];
        $entity = self::article(1, 'hello-world', true);

        return [
            'header'                => self::factory()->publicArticleHeader('Hello world', 'A short excerpt.'),
            'page_title'            => 'Hello world',
            'page_description'      => 'A short excerpt.',
            'current_route'         => 'blog_show',
            'article'               => $article,
            'article_id'            => 1,
            'comments'              => [self::comment(1, $entity, BlogCommentStatus::Approved, 'Ada', 'Great article.')],
            'comment_form'          => self::forms()->create(PublicBlogCommentType::class, null, ['action' => '/blog/hello-world/comments'])->createView(),
            'reply_forms'           => [],
            'can_moderate_comments' => false,
            'filters'               => ['q' => '', 'tag' => ''],
            'search_form'           => self::searchForm(),
            'blog_settings'         => [],
            'sidebar_related'       => [self::articleData('second-post', 'Second post')],
            'sidebar_tags'          => [['slug' => 'php', 'name' => 'PHP', 'count' => 3]],
            'sidebar_resources'     => [['type' => 'image', 'title' => 'Diagram', 'image' => '/uploads/diagram.webp', 'url' => null]],
            'aside_left'            => [],
            'aside_right'           => ['related', 'tags', 'resources'],
            'show_share'            => true,
            'show_comments'         => true,
            'show_source_link'      => true,
            'hero_image_mode'       => 'contain',
            'breadcrumbs'           => [],
        ];
    }

    public static function factory(?BlogKitAccessCheckerInterface $accessChecker = null, ?BlogKitPages $pages = null): BlogKitPageFactory
    {
        $urls = self::urlGenerator();

        return new BlogKitPageFactory($urls, $pages ?? new BlogKitPages(), new BlogKitAreaNav($urls, $accessChecker ?? new AllowAllBlogKitAccessChecker()));
    }

    public static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }
        };
    }

    public static function article(int $id, string $slug, bool $published): BlogArticle
    {
        $article = new BlogArticle()->setId($id)->setSlug($slug)->setPublished($published)->setPosition($id);
        $article->ensureTranslations([self::LOCALE, 'es']);
        $article->getTranslationOrFallback(self::LOCALE)->setTitle('Article ' . $slug);
        $article->getTranslationOrFallback('es')->setTitle('Artículo ' . $slug);

        return $article;
    }

    private static function tag(int $id, string $slug, string $name): BlogTag
    {
        $tag = new BlogTag()->setId($id)->setSlug($slug);
        $tag->ensureTranslations([self::LOCALE, 'es']);
        $tag->getTranslationOrFallback(self::LOCALE)->setName($name);
        $tag->getTranslationOrFallback('es')->setName($name);

        return $tag;
    }

    private static function comment(int $id, BlogArticle $article, BlogCommentStatus $status, string $author, string $body): BlogComment
    {
        return new BlogComment()
            ->setId($id)
            ->setArticle($article)
            ->setStatus($status)
            ->setAuthorName($author)
            ->setBody($body)
            ->setCreatedAt(new DateTimeImmutable('2026-10-20 10:30:00'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function articleData(string $slug, string $title, bool $image = true): array
    {
        return [
            'id'           => 1,
            'slug'         => $slug,
            'title'        => $title,
            'excerpt'      => 'A short excerpt.',
            'image'        => $image ? '/uploads/' . $slug . '.webp' : null,
            'published_at' => '2026-10-20',
            'tags'         => [['slug' => 'php', 'name' => 'PHP']],
        ];
    }

    /**
     * @param class-string $type
     * @param array<string, mixed> $data
     */
    private static function filterForm(string $type, array $data = []): FormView
    {
        return new GetFilterFormFactory(self::forms())->create($type, $data, ['action' => '/filter'])->createView();
    }

    private static function searchForm(): FormView
    {
        return self::forms()->create(BlogPublicSearchType::class, ['q' => '', 'tag' => ''], ['action' => '/blog', 'method' => 'GET'])->createView();
    }

    private static function csrfForm(string $action, string $tokenId): FormView
    {
        return new CsrfOnlyFormFactory(self::forms())->createNamed($action, $tokenId)->createView();
    }

    private static function forms(): FormFactoryInterface
    {
        $locales = new BlogLocales(self::LOCALE, [self::LOCALE, 'es']);
        $types   = [
            FormKitTestSupport::createType(BlogSettingsType::class),
            FormKitTestSupport::createType(BlogArticleType::class, blogLocales: $locales),
            FormKitTestSupport::createType(BlogArticleTranslationType::class, blogLocales: $locales),
            FormKitTestSupport::createType(BlogArticleInlineTranslationType::class, blogLocales: $locales),
            FormKitTestSupport::createType(BlogArticleResourceType::class),
            FormKitTestSupport::createType(BlogInlineModalType::class, blogLocales: $locales),
            FormKitTestSupport::createType(BlogTagType::class, blogLocales: $locales),
            FormKitTestSupport::createType(BlogTagTranslationType::class, blogLocales: $locales),
            FormKitTestSupport::createType(BlogPublicSearchType::class),
            FormKitTestSupport::createType(PublicBlogCommentType::class),
            FormKitTestSupport::createType(StaffBlogCommentReplyType::class),
            FormKitTestSupport::createType(BlogArticleFilterType::class, 'filter'),
            FormKitTestSupport::createType(BlogCommentFilterType::class, 'filter'),
            FormKitTestSupport::createType(BlogTagFilterType::class, 'filter'),
            FormKitTestSupport::createType(CsrfOnlyType::class, 'filter'),
        ];

        return Forms::createFormFactoryBuilder()
            ->addExtension(new DoctrineOrmExtension(self::registry()))
            ->addExtension(new CsrfExtension(self::csrfTokens()))
            ->addTypeExtension(new LocaleTabsExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new PreloadedExtension($types, []))
            ->getFormFactory();
    }

    /** In-memory SQLite registry for the article tag field (EntityType); no tag rows. */
    private static function registry(): TestManagerRegistry
    {
        if (self::$registry instanceof TestManagerRegistry) {
            return self::$registry;
        }

        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity', __DIR__], true);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        // Service repositories (ServiceEntityRepository) take the registry: build them from it.
        $configuration->setRepositoryFactory(new class implements RepositoryFactory {
            public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
            {
                $class      = $entityManager->getClassMetadata($entityName)->customRepositoryClassName ?? EntityRepository::class;
                $repository = $class === EntityRepository::class
                    ? new EntityRepository($entityManager, $entityManager->getClassMetadata($entityName))
                    : new $class(PageFixtures::registryFor());

                return $repository instanceof EntityRepository ? $repository : throw new LogicException($class);
            }
        });
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        $eventManager = new EventManager();
        $resolver     = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(BlogUserInterface::class, MappedTestUser::class, []);
        $eventManager->addEventSubscriber($resolver);

        $entityManager  = new EntityManager($connection, $configuration, $eventManager);
        self::$registry = new TestManagerRegistry($entityManager, $connection);
        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return self::$registry;
    }

    /** @internal registry of the fixture repository factory */
    public static function registryFor(): TestManagerRegistry
    {
        return self::registry();
    }

    /** Deterministic CSRF tokens (fixtures are rendered several times). */
    private static function csrfTokens(): CsrfTokenManagerInterface
    {
        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'REDACTED-TEST-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };
    }
}
