<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit;

use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Command\SyncBlogHashtagsCommand;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleResource;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticlePublishedDoctrineSubscriber;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitAdminAccessSubscriber;
use Nowo\BlogKitBundle\Form\AbstractBlogFormType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Service\BlogArticleBodyEnhancer;
use Nowo\BlogKitBundle\Service\BlogCatalog;
use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Nowo\BlogKitBundle\Service\BlogLocalesLocaleResolver;
use Nowo\BlogKitBundle\Service\BlogPostBodyFormatter;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CoverageGapsTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function blogCommentStatusLabels(): void
    {
        self::assertSame('pending', BlogCommentStatus::Pending->label());
        self::assertSame('approved', BlogCommentStatus::Approved->label());
        self::assertSame('rejected', BlogCommentStatus::Rejected->label());
    }

    #[Test]
    public function entityAccessorsAndFallbacks(): void
    {
        $article = new BlogArticle();
        self::assertNull($article->getPublishedAt());
        self::assertSame('', $article->getTitle());
        self::assertSame('', $article->getTranslationOrFallback('fr')->getTitle());
        $article->ensureTranslations();
        self::assertNotNull($article->getTranslation('es'));
        $article->getTranslation('es')?->setTitle('Hola');
        self::assertSame('Hola', $article->getTitle());
        $article->setId(42);
        self::assertSame(42, $article->getId());

        $onlyEn = new BlogArticle();
        $onlyEn->addTranslation((new BlogArticleTranslation())->setLocale('en')->setTitle('EN'));
        self::assertSame('EN', $onlyEn->getTranslationOrFallback('fr')->getTitle());
        self::assertSame('EN', $onlyEn->getTranslationOrFallback('fr', 'en')->getTitle());
        self::assertSame('EN', $onlyEn->getTranslationOrFallback('fr', 'es')->getTitle());

        $resource = (new BlogArticleResource())->setArticle($article);
        self::assertSame($article, $resource->getArticle());
        self::assertNull($resource->getId());
        $resource->setId(7);
        self::assertSame(7, $resource->getId());

        $tr = (new BlogArticleTranslation())
            ->setTranslatable($article)
            ->setExcerpt(null)
            ->setMetaDescription(null);
        self::assertNull($tr->getId());
        $tr->setId(8);
        self::assertSame(8, $tr->getId());
        self::assertSame($article, $tr->getTranslatable());
        self::assertNull($tr->getExcerpt());
        self::assertNull($tr->getMetaDescription());

        $tag = new BlogTag();
        self::assertSame('', $tag->getName());
        $tagTr = (new BlogTagTranslation())->setTranslatable($tag)->setName('PHP');
        self::assertSame($tag, $tagTr->getTranslatable());
        self::assertSame('PHP', $tagTr->getName());
        $tag->addTranslation($tagTr->setLocale('es'));
        self::assertSame('PHP', $tag->getName());
        self::assertSame('PHP', $tag->getName('es'));
        self::assertSame('PHP', $tag->getTranslationOrFallback('fr', 'es')->getName());
        self::assertSame('PHP', $tag->getTranslationOrFallback('fr', 'en')->getName());
        $tag->setId(9);
        $tagTr->setId(10);
        self::assertSame(9, $tag->getId());
        self::assertSame(10, $tagTr->getId());

        $comment = new BlogComment();
        self::assertInstanceOf(DateTimeImmutable::class, $comment->getCreatedAt());
        $comment->setId(11);
        self::assertSame(11, $comment->getId());

        $settings = new BlogSettings();
        $settings->setId(12);
        self::assertSame(12, $settings->getId());
    }

    #[Test]
    public function repositoryMapArticleRowsFormatsDateTimeInstances(): void
    {
        $repository = new BlogArticleRepository(
            $this->createMock(ManagerRegistry::class),
            LocaleTestSupport::create(),
        );
        $method = new ReflectionMethod(BlogArticleRepository::class, 'mapArticleRows');

        $result = $method->invoke($repository, [[
            'id'               => 7,
            'slug'             => 'published-post',
            'title'            => 'Published post',
            'meta_title'       => 'SEO title',
            'meta_description' => 'SEO description',
            'excerpt'          => 'Excerpt',
            'body'             => 'Body',
            'image'            => '/image.png',
            'published_at'     => new DateTimeImmutable('2026-08-18T10:00:00+00:00'),
            'linkedin_url'     => 'https://linkedin.example/post',
        ]], [7 => [['slug' => 'php', 'name' => 'PHP']]]);

        self::assertSame('2026-08-18', $result[0]['published_at']);
        self::assertSame([['slug' => 'php', 'name' => 'PHP']], $result[0]['tags']);
    }

    #[Test]
    public function abstractFormTypeReturnsSnakeCaseAliasWithoutBackslash(): void
    {
        $type = new class(FormKitTestSupport::merger(), FormKitTestSupport::typeMap()) extends AbstractBlogFormType {
            public function resolve(string $value): string
            {
                return Closure::bind(
                    fn (string $type): string => $this->resolveTypeAlias($type),
                    $this,
                    AbstractBlogFormType::class,
                )($value);
            }
        };

        self::assertSame('textarea', $type->resolve('textarea'));
    }

    #[Test]
    public function adminAccessSubscriberCoversDeniedAndNonAdminRoutes(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->method('canModerate')->willReturn(false);
        $checker->method('canConfigure')->willReturn(false);
        $checker->method('canManage')->willReturn(false);
        $subscriber = new BlogKitAdminAccessSubscriber($checker);

        self::assertArrayHasKey('kernel.controller', $subscriber::getSubscribedEvents());

        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/x');
        $request->attributes->set('_route', 123);
        $subscriber->onKernelController(new ControllerEvent($kernel, static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));

        $request->attributes->set('_route', 'blog_index');
        $subscriber->onKernelController(new ControllerEvent($kernel, static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));

        $request->attributes->set('_route', 'admin_blog_comments_index');
        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController(new ControllerEvent($kernel, static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));
    }

    #[Test]
    public function adminAccessSubscriberDeniesSettingsAndManageRoutes(): void
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->method('canModerate')->willReturn(true);
        $checker->method('canConfigure')->willReturn(false);
        $checker->method('canManage')->willReturn(false);
        $subscriber = new BlogKitAdminAccessSubscriber($checker);
        $kernel     = $this->createMock(HttpKernelInterface::class);

        $settings = Request::create('/admin/blog/settings');
        $settings->attributes->set('_route', 'admin_blog_settings');
        try {
            $subscriber->onKernelController(new ControllerEvent($kernel, static fn () => null, $settings, HttpKernelInterface::MAIN_REQUEST));
            self::fail('Expected AccessDeniedException for settings');
        } catch (AccessDeniedException) {
        }

        $manage = Request::create('/admin/blog');
        $manage->attributes->set('_route', 'admin_blog_index');
        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController(new ControllerEvent($kernel, static fn () => null, $manage, HttpKernelInterface::MAIN_REQUEST));
    }

    #[Test]
    public function publishedSubscriberSkipsNonArticleUpdatesAndMissingPublishedChangeSet(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');
        $subscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([new stdClass()]);
        $uow->method('getScheduledEntityUpdates')->willReturn([new BlogArticle(), (new BlogArticle())->setPublished(true)]);
        $uow->method('getEntityChangeSet')->willReturnOnConsecutiveCalls([], ['published' => 'invalid']);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $args = $this->createMock(OnFlushEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        $subscriber->onFlush($args);
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    #[Test]
    public function syncHashtagsSkipsEmptyBodiesEmptyDefinitionsAndUnchangedTagSets(): void
    {
        $existing = (new BlogTag())->setSlug('symfony');
        $existing->ensureTranslations();

        $article = new BlogArticle();
        $article->addTranslation((new BlogArticleTranslation())->setLocale('es')->setBody(''));
        $article->addTranslation((new BlogArticleTranslation())->setLocale('en')->setBody('Body without tags'));
        $article->addTag($existing);

        $articleRepository = $this->createMock(BlogArticleRepository::class);
        $qb                = $this->createMock(QueryBuilder::class);
        $query             = $this->createMock(Query::class);
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$article]);
        $articleRepository->method('createQueryBuilder')->willReturn($qb);

        $tagRepository = $this->createMock(BlogTagRepository::class);
        $tagRepository->method('findAllOrdered')->willReturn([$existing]);

        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->method('processHtmlBody')->willReturn(['body' => 'Body without tags', 'hashtags' => []]);
        $processor->method('mapToTagDefinitions')->willReturnOnConsecutiveCalls([], ['symfony' => 'Symfony']);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $tester = new CommandTester(new SyncBlogHashtagsCommand($articleRepository, $tagRepository, $processor, $em, LocaleTestSupport::create()));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    #[Test]
    public function catalogTagsReturnsUntruncatedWhenUnderLimit(): void
    {
        $tags    = [['slug' => 'a', 'name' => 'A', 'count' => 1]];
        $tagRepo = $this->createMock(BlogTagRepository::class);
        $tagRepo->method('findPublishedTagSummaries')->willReturn($tags);
        $settings = $this->createMock(BlogSettingsProvider::class);
        $settings->method('indexTagsLimit')->willReturn(10);

        $catalog = new BlogCatalog(
            new RequestStack(),
            $this->createMock(BlogArticleRepository::class),
            $tagRepo,
            $settings,
            $this->createMock(BlogHashtagProcessor::class),
            new BlogArticleBodyEnhancer(),
            new BlogLocalesLocaleResolver(new BlogLocales('es', ['es', 'en'])),
        );

        self::assertSame($tags, $catalog->blogTags());
    }

    #[Test]
    public function hashtagAndBodyFormatterGapBranches(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/blog');

        $processor = new BlogHashtagProcessor($translator, $urlGenerator);
        self::assertSame([], $processor->mapToTagSlugs(['', '###']));
        self::assertSame(['ai'], $processor->mapToTagSlugs(['AI', 'AI', 'ia']));

        $formatter = new BlogPostBodyFormatter($processor);
        self::assertStringContainsString('<ul>', $formatter->format("✔ one\n\n✔ two"));
        self::assertStringContainsString('<p>text</p>', $formatter->format("- first\n \ntext"));
    }
}
