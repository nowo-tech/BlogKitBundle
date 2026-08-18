<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\BlogKitBundle\Command\SyncBlogHashtagsCommand;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogHashtagProcessor;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncBlogHashtagsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function dryRunReportsChangesWithoutPersistingData(): void
    {
        $article           = $this->createArticleWithBody('Original body');
        $articleRepository = $this->createArticleRepositoryReturning([$article]);
        $tagRepository     = $this->createMock(BlogTagRepository::class);
        $tagRepository->method('findAllOrdered')->willReturn([]);

        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->method('processHtmlBody')->willReturn([
            'body'     => 'Updated body',
            'hashtags' => ['Symfony'],
        ]);
        $processor->method('mapToTagDefinitions')->willReturn([
            'symfony' => 'Symfony',
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $tester = new CommandTester(new SyncBlogHashtagsCommand(
            $articleRepository,
            $tagRepository,
            $processor,
            $entityManager,
            LocaleTestSupport::create(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertMatchesRegularExpression(
            '/Dry-run:\s*1 bodies with hashtags,\s*1 bodies rewritten,\s*1 tags created,\s*1 articles relinked/',
            preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '',
        );
        self::assertSame('Original body', $article->getTranslationOrFallback('es')->getBody());
        self::assertCount(0, $article->getTags());
    }

    #[Test]
    public function executeRewritesBodiesCreatesTagsAndRelinksArticles(): void
    {
        $article           = $this->createArticleWithBody('Original body');
        $articleRepository = $this->createArticleRepositoryReturning([$article]);
        $tagRepository     = $this->createMock(BlogTagRepository::class);
        $tagRepository->method('findAllOrdered')->willReturn([]);

        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->method('processHtmlBody')->willReturn([
            'body'     => 'Updated body',
            'hashtags' => ['Symfony'],
        ]);
        $processor->method('mapToTagDefinitions')->willReturn([
            'symfony' => 'Symfony',
        ]);

        $persisted     = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entity) use (&$persisted): bool {
                $persisted[] = $entity;

                return $entity instanceof BlogTag;
            }));
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new SyncBlogHashtagsCommand(
            $articleRepository,
            $tagRepository,
            $processor,
            $entityManager,
            LocaleTestSupport::create(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression(
            '/Done:\s*1 bodies with hashtags,\s*1 bodies rewritten,\s*1 tags created,\s*1 articles relinked/',
            preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '',
        );
        self::assertSame('Updated body', $article->getTranslationOrFallback('es')->getBody());
        self::assertCount(1, $article->getTags());

        /** @var BlogTag $tag */
        $tag = $article->getTags()->first();
        self::assertInstanceOf(BlogTag::class, $tag);
        self::assertSame('symfony', $tag->getSlug());
        self::assertSame('Symfony', $tag->getTranslationOrFallback('es')->getName());
        self::assertSame('Symfony', $tag->getTranslationOrFallback('en')->getName());
        self::assertContains($tag, $persisted);
    }

    #[Test]
    public function executeSkipsRelinkingWhenResolvedTagSlugsAlreadyMatchCurrentOnes(): void
    {
        $tag = (new BlogTag())->setSlug('symfony');
        $tag->ensureTranslations();

        $article = $this->createArticleWithBody('Original body');
        $article->addTag($tag);
        $articleRepository = $this->createArticleRepositoryReturning([$article]);

        $tagRepository = $this->createMock(BlogTagRepository::class);
        $tagRepository->method('findAllOrdered')->willReturn([$tag]);

        $processor = $this->createMock(BlogHashtagProcessor::class);
        $processor->method('processHtmlBody')->willReturn([
            'body'     => 'Original body',
            'hashtags' => ['Symfony'],
        ]);
        $processor->method('mapToTagDefinitions')->willReturn([
            'symfony' => 'Symfony',
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new SyncBlogHashtagsCommand(
            $articleRepository,
            $tagRepository,
            $processor,
            $entityManager,
            LocaleTestSupport::create(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression(
            '/Done:\s*1 bodies with hashtags,\s*0 bodies rewritten,\s*0 tags created,\s*0 articles relinked/',
            preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '',
        );
        self::assertCount(1, $article->getTags());
    }

    private function createArticleWithBody(string $body): BlogArticle
    {
        $article     = new BlogArticle();
        $translation = (new BlogArticleTranslation())
            ->setLocale('es')
            ->setBody($body);
        $article->addTranslation($translation);

        return $article;
    }

    /**
     * @param list<BlogArticle> $articles
     */
    private function createArticleRepositoryReturning(array $articles): BlogArticleRepository
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($articles);

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['leftJoin', 'addSelect', 'getQuery'])
            ->getMock();
        $queryBuilder->method('leftJoin')->willReturnSelf();
        $queryBuilder->method('addSelect')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(BlogArticleRepository::class);
        $repository->method('createQueryBuilder')->with('b')->willReturn($queryBuilder);

        return $repository;
    }
}
