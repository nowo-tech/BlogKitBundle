<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Reformats trailing LinkedIn hashtags in article bodies, creates matching blog tags, and links them.
 */
#[AsCommand(
    name: 'nowo:blog:sync-hashtags',
    description: 'Format trailing hashtags in blog bodies, create tags, and link articles',
)]
final class SyncBlogHashtagsCommand extends Command
{
    public function __construct(
        private readonly BlogArticleRepository $blogArticleRepository,
        private readonly BlogTagRepository $blogTagRepository,
        private readonly BlogHashtagProcessor $blogHashtagProcessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly BlogLocales $blogLocales,
    ) {
        parent::__construct();
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

        $articles = $this->blogArticleRepository->createQueryBuilder('b')
            ->leftJoin('b.translations', 'bt')->addSelect('bt')
            ->leftJoin('b.tags', 'tg')->addSelect('tg')
            ->getQuery()
            ->getResult();
        $bodiesUpdated    = 0;
        $tagsCreated      = 0;
        $articlesRelinked = 0;
        $hashtagHits      = 0;

        foreach ($articles as $article) {
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
                    ++$hashtagHits;
                }

                if ($result['body'] !== $body) {
                    if (!$dryRun) {
                        $translation->setBody($result['body']);
                    }

                    ++$bodiesUpdated;
                }

                $localeDefinitions = $this->blogHashtagProcessor->mapToTagDefinitions($result['hashtags']);

                foreach ($localeDefinitions as $slug => $name) {
                    if ($translation->getLocale() === $preferredLocale || !isset($definitions[$slug])) {
                        $definitions[$slug] = $name;
                    }
                }
            }

            if ($definitions === []) {
                continue;
            }

            $resolved = [];

            foreach ($definitions as $slug => $name) {
                $tag = $tagsBySlug[$slug] ?? null;

                if (!$tag instanceof BlogTag) {
                    if ($dryRun) {
                        ++$tagsCreated;
                        continue;
                    }

                    $tag = new BlogTag()->setSlug($slug);
                    $tag->ensureTranslations($this->blogLocales->getAll());

                    foreach ($this->blogLocales->getAll() as $locale) {
                        $tag->getTranslationOrFallback($locale, $preferredLocale)->setName($name);
                    }

                    $this->entityManager->persist($tag);
                    $tagsBySlug[$slug] = $tag;
                    ++$tagsCreated;
                }

                $resolved[] = $tag;
            }

            if ($dryRun) {
                ++$articlesRelinked;
                continue;
            }

            $currentSlugs = array_map(
                static fn (BlogTag $blogTag): string => $blogTag->getSlug(),
                $article->getTags()->toArray(),
            );
            sort($currentSlugs);
            $nextSlugs = array_map(static fn (BlogTag $blogTag): string => $blogTag->getSlug(), $resolved);
            sort($nextSlugs);

            if ($currentSlugs === $nextSlugs) {
                continue;
            }

            $article->setTags($resolved);
            ++$articlesRelinked;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $symfonyStyle->success(sprintf(
            '%s: %d bodies with hashtags, %d bodies rewritten, %d tags created, %d articles relinked',
            $dryRun ? 'Dry-run' : 'Done',
            $hashtagHits,
            $bodiesUpdated,
            $tagsCreated,
            $articlesRelinked,
        ));

        return Command::SUCCESS;
    }
}
