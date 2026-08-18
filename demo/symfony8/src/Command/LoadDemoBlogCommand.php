<?php

declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_unique;
use function array_values;
use function count;
use function htmlspecialchars;
use function implode;
use function sprintf;

use const ENT_HTML5;
use const ENT_QUOTES;

#[AsCommand(
    name: 'app:load-demo-blog',
    description: 'Load 32 lorem ipsum demo articles with distinct tags',
)]
final class LoadDemoBlogCommand extends Command
{
    private const ARTICLE_COUNT = 32;
    private const SLUG_PREFIX   = 'lorem-ipsum-';

    /** @var list<array{slug: string, es: string, en: string}> */
    private const TAGS = [
        ['slug' => 'symfony', 'es' => 'Symfony', 'en' => 'Symfony'],
        ['slug' => 'php', 'es' => 'PHP', 'en' => 'PHP'],
        ['slug' => 'frankenphp', 'es' => 'FrankenPHP', 'en' => 'FrankenPHP'],
        ['slug' => 'doctrine', 'es' => 'Doctrine', 'en' => 'Doctrine'],
        ['slug' => 'twig', 'es' => 'Twig', 'en' => 'Twig'],
        ['slug' => 'seguridad', 'es' => 'Seguridad', 'en' => 'Security'],
        ['slug' => 'rendimiento', 'es' => 'Rendimiento', 'en' => 'Performance'],
        ['slug' => 'ux', 'es' => 'UX', 'en' => 'UX'],
        ['slug' => 'i18n', 'es' => 'i18n', 'en' => 'i18n'],
        ['slug' => 'docker', 'es' => 'Docker', 'en' => 'Docker'],
        ['slug' => 'tests', 'es' => 'Tests', 'en' => 'Testing'],
        ['slug' => 'cms', 'es' => 'CMS', 'en' => 'CMS'],
    ];

    /** @var list<array{es: string, en: string}> */
    private const TOPICS = [
        ['es' => 'Primeros pasos con Symfony 8', 'en' => 'Getting started with Symfony 8'],
        ['es' => 'PHP 8.4 en la práctica', 'en' => 'PHP 8.4 in practice'],
        ['es' => 'FrankenPHP y el modo worker', 'en' => 'FrankenPHP and worker mode'],
        ['es' => 'Doctrine ORM con SQLite', 'en' => 'Doctrine ORM with SQLite'],
        ['es' => 'Plantillas Twig para el blog', 'en' => 'Twig templates for the blog'],
        ['es' => 'Seguridad HTTP Basic en el admin', 'en' => 'HTTP Basic security in admin'],
        ['es' => 'Rendimiento del listado paginado', 'en' => 'Paginated listing performance'],
        ['es' => 'Experiencia de usuario en masonry', 'en' => 'Masonry user experience'],
        ['es' => 'Traducciones en español e inglés', 'en' => 'Spanish and English translations'],
        ['es' => 'Docker Compose para el demo', 'en' => 'Docker Compose for the demo'],
        ['es' => 'Tests funcionales del bundle', 'en' => 'Functional tests for the bundle'],
        ['es' => 'Un CMS ligero con etiquetas', 'en' => 'A lightweight CMS with tags'],
        ['es' => 'Comentarios y moderación', 'en' => 'Comments and moderation'],
        ['es' => 'Imágenes hero y metadatos', 'en' => 'Hero images and metadata'],
        ['es' => 'Rutas públicas del blog', 'en' => 'Public blog routes'],
        ['es' => 'El panel de ajustes del blog', 'en' => 'The blog settings panel'],
        ['es' => 'Infinite scroll frente a páginas', 'en' => 'Infinite scroll versus pages'],
        ['es' => 'Hashtags y etiquetas', 'en' => 'Hashtags and tags'],
        ['es' => 'Auditoría de cambios', 'en' => 'Change auditing'],
        ['es' => 'Formularios de filtro', 'en' => 'Filter forms'],
        ['es' => 'Assets Vite y TypeScript', 'en' => 'Vite assets and TypeScript'],
        ['es' => 'Locales inyectados en servicios', 'en' => 'Locales injected into services'],
        ['es' => 'La ficha de un artículo', 'en' => 'The article detail page'],
        ['es' => 'Recursos adjuntos al post', 'en' => 'Resources attached to a post'],
        ['es' => 'SEO: title y description', 'en' => 'SEO: title and description'],
        ['es' => 'El aside de últimas entradas', 'en' => 'The latest posts aside'],
        ['es' => 'Nube de tags del índice', 'en' => 'Index tag cloud'],
        ['es' => 'Publicar y fechar entradas', 'en' => 'Publishing and dating posts'],
        ['es' => 'Bootstrap 5 en la interfaz', 'en' => 'Bootstrap 5 in the UI'],
        ['es' => 'Lorem ipsum como contenido demo', 'en' => 'Lorem ipsum as demo content'],
        ['es' => 'Relacionados por etiqueta', 'en' => 'Related posts by tag'],
        ['es' => 'Cierre: un blog de demostración', 'en' => 'Wrap-up: a demonstration blog'],
    ];

    private const LOREM = [
        'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
        'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
        'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
        'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
        'Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas.',
        'Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae.',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Remove existing demo articles and recreate them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if ($force) {
            $removed = $this->removeDemoArticles();
            $io->note(sprintf('Removed %d existing demo article(s).', $removed));
        }

        $existing = $this->countDemoArticles();
        if ($existing >= self::ARTICLE_COUNT) {
            $io->success(sprintf('Demo blog already has %d lorem ipsum articles. Use --force to recreate.', $existing));

            return Command::SUCCESS;
        }

        $tags = $this->ensureTags();
        $created = 0;

        for ($n = 1; $n <= self::ARTICLE_COUNT; ++$n) {
            $slug = sprintf('%s%02d', self::SLUG_PREFIX, $n);
            if ($this->findArticleBySlug($slug) instanceof BlogArticle) {
                continue;
            }

            $topic   = self::TOPICS[$n - 1];
            $article = new BlogArticle();
            $article
                ->setSlug($slug)
                ->setPublished(true)
                ->setPosition($n)
                ->setPublishedAt(new DateTimeImmutable(sprintf('-%d days', self::ARTICLE_COUNT - $n)))
                ->setImage(sprintf('https://picsum.photos/seed/blogkit-%02d/960/540', $n))
                ->ensureTranslations(['es', 'en']);

            $this->fillTranslation($article, 'es', $topic['es'], $n);
            $this->fillTranslation($article, 'en', $topic['en'], $n);

            foreach ($this->tagIndexesForArticle($n) as $tagIndex) {
                $article->addTag($tags[$tagIndex]);
            }

            $this->entityManager->persist($article);
            ++$created;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d demo article(s) with %d distinct tags.', $created, count($tags)));

        return Command::SUCCESS;
    }

    private function fillTranslation(BlogArticle $article, string $locale, string $title, int $n): void
    {
        $translation = $article->getTranslation($locale);
        if ($translation === null) {
            return;
        }

        $excerpt = sprintf('%s Lorem ipsum dolor sit amet, consectetur adipiscing elit.', $title);
        $translation
            ->setTitle($title)
            ->setMetaTitle($title)
            ->setMetaDescription($excerpt)
            ->setExcerpt($excerpt)
            ->setBody($this->loremBody($n, $title));
    }

    private function loremBody(int $n, string $title): string
    {
        $paragraphs = [
            sprintf('<p><strong>%s.</strong> %s</p>', htmlspecialchars($title, ENT_QUOTES | ENT_HTML5), self::LOREM[$n % count(self::LOREM)]),
        ];

        for ($i = 0; $i < 4; ++$i) {
            $paragraphs[] = sprintf('<p>%s</p>', self::LOREM[($n + $i) % count(self::LOREM)]);
        }

        return implode("\n", $paragraphs);
    }

    /**
     * @return list<int>
     */
    private function tagIndexesForArticle(int $n): array
    {
        $count = count(self::TAGS);
        $indexes = [
            ($n - 1) % $count,
            $n % $count,
        ];

        if ($n % 3 === 0) {
            $indexes[] = ($n + 4) % $count;
        }

        return array_values(array_unique($indexes));
    }

    /**
     * @return list<BlogTag>
     */
    private function ensureTags(): array
    {
        $tags = [];

        foreach (self::TAGS as $definition) {
            $tag = $this->entityManager->getRepository(BlogTag::class)->findOneBy(['slug' => $definition['slug']]);
            if (!$tag instanceof BlogTag) {
                $tag = new BlogTag();
                $tag->setSlug($definition['slug']);
                $this->entityManager->persist($tag);
            }

            $tag->ensureTranslations(['es', 'en']);
            $tag->getTranslation('es')?->setName($definition['es']);
            $tag->getTranslation('en')?->setName($definition['en']);
            $tags[] = $tag;
        }

        $this->entityManager->flush();

        return $tags;
    }

    private function findArticleBySlug(string $slug): ?BlogArticle
    {
        return $this->entityManager->getRepository(BlogArticle::class)->findOneBy(['slug' => $slug]);
    }

    private function countDemoArticles(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(BlogArticle::class, 'a')
            ->where('a.slug LIKE :prefix')
            ->setParameter('prefix', self::SLUG_PREFIX . '%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function removeDemoArticles(): int
    {
        /** @var list<BlogArticle> $articles */
        $articles = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(BlogArticle::class, 'a')
            ->where('a.slug LIKE :prefix')
            ->setParameter('prefix', self::SLUG_PREFIX . '%')
            ->getQuery()
            ->getResult();

        foreach ($articles as $article) {
            $this->entityManager->remove($article);
        }

        $this->entityManager->flush();

        return count($articles);
    }
}
