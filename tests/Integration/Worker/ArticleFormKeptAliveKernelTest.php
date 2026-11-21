<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_map;
use function array_slice;
use function count;
use function gc_collect_cycles;
use function implode;
use function in_array;
use function intdiv;
use function intval;
use function memory_get_usage;
use function parse_str;
use function rawurlencode;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Since 1.12.1 (REQ-RUNTIME-002): the article form (`/admin/blog/new`, `/admin/blog/{id}/edit`) through ONE kept-alive
 * kernel (FrankenPHP worker mode, kernel reset false: `handle()` + `terminate()`, the services resetter never runs),
 * with the blog's manager cleared between requests: by your code on a standard host (your manager is yours to clear),
 * by the bundle on a DDD host (its own manager) and with the private manager (dropped at every boundary).
 *
 * Up to 1.12.0 the tag field was an entity choice: the shared form choice-list cache kept the tags loaded by the first
 * request, the next request rendered those detached tags and answered 500 ("passed to the choice field must be
 * managed"). The field now offers tag ids read per form build, and a submit stores the tags of the current manager.
 */
final class ArticleFormKeptAliveKernelTest extends TestCase
{
    use CollectsDeprecations;

    private const int ITERATIONS = 300;

    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

    private ?WorkerTestKernel $kernel = null;

    private string $directory = '';

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        new Filesystem()->remove([$this->directory, sys_get_temp_dir() . '/nowo-blog-kit-worker']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function modes(): iterable
    {
        yield 'standard host, your manager cleared by you' => [WorkerTestKernel::STANDARD];
        yield 'DDD host, own manager cleared by the bundle' => [WorkerTestKernel::DDD];
        yield 'private entity manager' => [WorkerTestKernel::PRIVATE];
    }

    #[Test]
    #[DataProvider('modes')]
    public function editAndNewRenderOnEveryRequestAndASubmitStoresTheSelectedTags(string $mode): void
    {
        $container = $this->boot($mode);
        $manager   = $this->blogManager($container, $mode);
        $articleId = $this->seed($container, $mode, $manager);
        $kernel    = $this->httpKernel($container);
        $tagIds    = $this->tagIds($manager);

        // Two requests in a row on each page, the manager cleared in between.
        foreach (['/admin/blog/' . $articleId . '/edit', '/admin/blog/new'] as $path) {
            for ($request = 1; $request <= 2; ++$request) {
                $html = (string) $this->get($kernel, $path)->getContent();
                self::assertStringContainsString('PHP (php)', $html, $path . ', request ' . $request);
                self::assertStringContainsString('Symfony (symfony)', $html, $path . ', request ' . $request);
                $manager->clear();
            }
        }

        // The edit page selects the article's tag (value = tag id).
        self::assertMatchesRegularExpression('/<option value="' . $tagIds['php'] . '" selected="selected">PHP \(php\)/', (string) $this->get($kernel, '/admin/blog/' . $articleId . '/edit')->getContent());
        $manager->clear();

        // A submit replaces php by symfony with the tags of that request's manager.
        $form     = $this->get($kernel, '/admin/blog/' . $articleId . '/edit');
        $response = $this->submitTags($kernel, '/admin/blog/' . $articleId . '/edit', $form, [$tagIds['symfony']]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            [$tagIds['symfony']],
            array_map(intval(...), $manager->getConnection()->fetchFirstColumn('SELECT blog_tag_id FROM content_blog_article_tag WHERE blog_article_id = ?', [$articleId])),
        );
        $manager->clear();

        $samples = [];
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $path = $i % 2 === 0 ? '/admin/blog/' . $articleId . '/edit' : '/admin/blog/new';
            self::assertStringContainsString('Symfony (symfony)', (string) $this->get($kernel, $path)->getContent(), 'request ' . $i);
            $manager->clear();
            if ($i >= 50 && $i % 25 === 0) {
                gc_collect_cycles();
                $samples[] = memory_get_usage();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->growth($samples), 'memory stays flat over many requests (samples: ' . implode(', ', $samples) . ')');
    }

    private function boot(string $mode): ContainerInterface
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-worker-db-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);
        $this->kernel = new WorkerTestKernel($mode, $this->directory);
        // The standard host kernel (no persistence option) triggers the 1.12.0 notice when its container is built.
        self::deprecationsOf(fn () => $this->kernel?->boot());
        $container = $this->kernel->getContainer();

        foreach ($this->doctrine($container)->getManagers() as $manager) {
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }

        return $container;
    }

    private function doctrine(ContainerInterface $container): ManagerRegistry
    {
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    /**
     * The manager the blog reads through in each mode.
     */
    private function blogManager(ContainerInterface $container, string $mode): EntityManagerInterface
    {
        $manager = $this->doctrine($container)->getManager($mode === WorkerTestKernel::DDD ? 'blog' : 'default');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * Alice's article tagged php, and two tags (php, symfony); returns the article id.
     */
    private function seed(ContainerInterface $container, string $mode, EntityManagerInterface $manager): int
    {
        $owner = new BlogUserRef('u-alice');
        if ($mode !== WorkerTestKernel::DDD) {
            $default = $this->blogManager($container, WorkerTestKernel::STANDARD);
            $owner   = new MappedTestUser()->setEmail('alice@example.test');
            $default->persist($owner);
            $default->flush();
            self::assertSame(1, $owner->getId());
        }

        $tags = [];
        foreach (['php' => 'PHP', 'symfony' => 'Symfony'] as $slug => $name) {
            $tag = new BlogTag()->setSlug($slug)->ensureTranslations(['es', 'en']);
            $tag->getTranslationOrFallback('es')->setName($name);
            $tag->getTranslationOrFallback('en')->setName($name);
            $manager->persist($tag);
            $tags[$slug] = $tag;
        }

        $article = $this->article($owner)->addTag($tags['php']);
        $manager->persist($article);
        $manager->flush();
        $id = (int) $article->getId();
        $manager->clear();

        return $id;
    }

    private function article(BlogUserInterface $owner): BlogArticle
    {
        $article = new BlogArticle()->setSlug('post-alice')->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle('Post of alice'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('Post of alice'));
        $article->setCreatedBy($owner);

        return $article;
    }

    /**
     * @return array<string, int> slug => id
     */
    private function tagIds(EntityManagerInterface $manager): array
    {
        $ids = [];
        foreach ($manager->getConnection()->fetchAllAssociative('SELECT id, slug FROM content_blog_tag') as $row) {
            $ids[(string) $row['slug']] = (int) $row['id'];
        }

        return $ids;
    }

    private function httpKernel(ContainerInterface $container): HttpKernelInterface
    {
        $kernel = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        return $kernel;
    }

    private function get(HttpKernelInterface $kernel, string $path): Response
    {
        $response = $this->handle($kernel, Request::create($path));
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $response;
    }

    /**
     * Posts the article form rendered in `$form` back as a browser would, with only the tag selection changed.
     *
     * @param list<int> $tagIds
     */
    private function submitTags(HttpKernelInterface $kernel, string $path, Response $form, array $tagIds): Response
    {
        $document = new DOMDocument();
        self::assertTrue(@$document->loadHTML((string) $form->getContent()));
        $fields = [];
        foreach (new DOMXPath($document)->query('//form[@name="blog_article"]//*[@name]') ?: [] as $field) {
            self::assertInstanceOf(DOMElement::class, $field);
            $name = $field->getAttribute('name');
            $type = $field->getAttribute('type');
            if ($field->tagName === 'button' || (in_array($type, ['checkbox', 'radio'], true) && !$field->hasAttribute('checked'))) {
                continue;
            }

            $value = match ($field->tagName) {
                'textarea' => $field->textContent,
                'select'   => null,
                default    => $field->getAttribute('value'),
            };
            if ($value !== null) {
                $fields[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
        }

        foreach ($tagIds as $tagId) {
            $fields[] = rawurlencode('blog_article[tags][]') . '=' . $tagId;
        }

        parse_str(implode('&', $fields), $parameters);
        $request = Request::create($path, 'POST', $parameters);
        foreach ($form->headers->getCookies() as $cookie) {
            self::assertInstanceOf(Cookie::class, $cookie);
            $request->cookies->set($cookie->getName(), (string) $cookie->getValue());
        }

        return $this->handle($kernel, $request);
    }

    private function handle(HttpKernelInterface $kernel, Request $request): Response
    {
        $request->headers->set('X-Worker-User', 'alice');
        $response = $kernel->handle($request);
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }

    /**
     * The growth between the medians of the first and the second half of the samples.
     *
     * @param list<int> $samples
     */
    private function growth(array $samples): int
    {
        $half = intdiv(count($samples), 2);

        return $this->median(array_slice($samples, $half)) - $this->median(array_slice($samples, 0, $half));
    }

    /**
     * @param list<int> $values
     */
    private function median(array $values): int
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
