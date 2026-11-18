<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Reformats trailing LinkedIn hashtags in article bodies, creates matching blog tags, and links them.
 *
 * Since 1.10.0 (REQ-RUNTIME-003): one unit of {@see BlogKitTransactionRunner} per article (small transactions, rule
 * 9), instead of one flush for the whole run. A tag created at the same time by another writer (the admin, a second
 * run) is a unique violation: the unit is run once more and links the winner's tag. A lock conflict stops the run
 * with an error (the articles already done stay saved; running it again is safe).
 */
#[AsCommand(
    name: 'nowo:blog:sync-hashtags',
    description: 'Format trailing hashtags in blog bodies, create tags, and link articles',
)]
final class SyncBlogHashtagsCommand extends Command
{
    /** @var array{hits: int, bodies: int, tags: int, relinked: int} */
    private const array NO_CHANGE = ['hits' => 0, 'bodies' => 0, 'tags' => 0, 'relinked' => 0];

    private readonly BlogKitTransactionRunner $transactionRunner;

    public function __construct(
        private readonly BlogArticleRepository $blogArticleRepository,
        private readonly BlogTagRepository $blogTagRepository,
        private readonly BlogHashtagProcessor $blogHashtagProcessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly BlogLocales $blogLocales,
        ?BlogKitTransactionRunner $transactionRunner = null,
    ) {
        parent::__construct();
        $this->transactionRunner = $transactionRunner ?? BlogKitTransactionRunner::unmanaged($entityManager);
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $dryRun       = (bool) $input->getOption('dry-run');

        /** @var array<string, BlogTag> $tagsBySlug */
        $tagsBySlug = [];

        foreach ($this->blogTagRepository->findAllOrdered() as $tag) {
            $tagsBySlug[$tag->getSlug()] = $tag;
        }

        /** @var list<BlogArticle> $articles */
        $articles = $this->blogArticleRepository->createQueryBuilder('b')
            ->leftJoin('b.translations', 'bt')->addSelect('bt')
            ->leftJoin('b.tags', 'tg')->addSelect('tg')
            ->getQuery()
            ->getResult();
        $totals = self::NO_CHANGE;

        foreach ($articles as $article) {
            if ($dryRun) {
                $totals = $this->add($totals, $this->syncArticle($article, $tagsBySlug, true));
                continue;
            }

            try {
                $totals = $this->add($totals, $this->transactionRunner->runRereadingOnDuplicate(
                    fn (): array => $this->syncManagedArticle($article, $tagsBySlug),
                ));
            } catch (TransientStorageFailure) {
                $symfonyStyle->error('Another write held the same rows (deadlock or lock wait timeout): nothing of this article was saved, the articles before it were. Run the command again.');

                return Command::FAILURE;
            }
        }

        $symfonyStyle->success(sprintf(
            '%s: %d bodies with hashtags, %d bodies rewritten, %d tags created, %d articles relinked',
            $dryRun ? 'Dry-run' : 'Done',
            $totals['hits'],
            $totals['bodies'],
            $totals['tags'],
            $totals['relinked'],
        ));

        return Command::SUCCESS;
    }

    /**
     * One unit (attempt) for one article. A unit replayed after a unique violation, or run after another unit failed,
     * may not use entities of a reset manager: the article and the tags are read again when they are not managed.
     *
     * @param array<string, BlogTag> $tagsBySlug
     *
     * @return array{hits: int, bodies: int, tags: int, relinked: int}
     */
    private function syncManagedArticle(BlogArticle $article, array &$tagsBySlug): array
    {
        if (!$this->entityManager->contains($article)) {
            $id      = $article->getId();
            $article = $id !== null ? $this->blogArticleRepository->find($id) : null;

            if (!$article instanceof BlogArticle) {
                return self::NO_CHANGE; // deleted meanwhile
            }
        }

        return $this->syncArticle($article, $tagsBySlug, false);
    }

    /**
     * @param array<string, BlogTag> $tagsBySlug
     *
     * @return array{hits: int, bodies: int, tags: int, relinked: int}
     */
    private function syncArticle(BlogArticle $article, array &$tagsBySlug, bool $dryRun): array
    {
        $counts = self::NO_CHANGE;

        /** @var array<string, string> $definitions slug => display name */
        $definitions     = [];
        $preferredLocale = $this->blogLocales->getDefault();

        foreach ($article->getTranslations() as $translation) {
            $body = (string) $translation->getBody();

            if (trim($body) === '') {
                continue;
            }

            $result = $this->blogHashtagProcessor->processHtmlBody($body);

            if ($result['hashtags'] !== []) {
                ++$counts['hits'];
            }

            if ($result['body'] !== $body) {
                if (!$dryRun) {
                    $translation->setBody($result['body']);
                }

                ++$counts['bodies'];
            }

            $localeDefinitions = $this->blogHashtagProcessor->mapToTagDefinitions($result['hashtags']);

            foreach ($localeDefinitions as $slug => $name) {
                if ($translation->getLocale() === $preferredLocale || !isset($definitions[$slug])) {
                    $definitions[$slug] = $name;
                }
            }
        }

        if ($definitions === []) {
            return $counts;
        }

        $resolved = [];

        foreach ($definitions as $slug => $name) {
            $slug = (string) $slug;
            $tag  = $tagsBySlug[$slug] ?? null;

            if ($dryRun) {
                if (!$tag instanceof BlogTag) {
                    ++$counts['tags'];
                }

                continue;
            }

            // Since 1.10.0: re-read a tag the manager does not hold (a concurrent writer may have created it).
            if (!$tag instanceof BlogTag || !$this->entityManager->contains($tag)) {
                $tag = $this->blogTagRepository->findBySlug($slug);
            }

            if (!$tag instanceof BlogTag) {
                $tag = new BlogTag()->setSlug($slug);
                $tag->ensureTranslations($this->blogLocales->getAll());

                foreach ($this->blogLocales->getAll() as $locale) {
                    $tag->getTranslationOrFallback($locale, $preferredLocale)->setName($name);
                }

                $this->entityManager->persist($tag);
                ++$counts['tags'];
            }

            $tagsBySlug[$slug] = $tag;
            $resolved[]        = $tag;
        }

        if ($dryRun) {
            ++$counts['relinked'];

            return $counts;
        }

        $currentSlugs = array_map(
            static fn (BlogTag $blogTag): string => $blogTag->getSlug(),
            $article->getTags()->toArray(),
        );
        sort($currentSlugs);
        $nextSlugs = array_map(static fn (BlogTag $blogTag): string => $blogTag->getSlug(), $resolved);
        sort($nextSlugs);

        if ($currentSlugs !== $nextSlugs) {
            $article->setTags($resolved);
            ++$counts['relinked'];
        }

        return $counts;
    }

    /**
     * @param array{hits: int, bodies: int, tags: int, relinked: int} $totals
     * @param array{hits: int, bodies: int, tags: int, relinked: int} $counts
     *
     * @return array{hits: int, bodies: int, tags: int, relinked: int}
     */
    private function add(array $totals, array $counts): array
    {
        foreach ($counts as $key => $count) {
            $totals[$key] += $count;
        }

        return $totals;
    }
}
