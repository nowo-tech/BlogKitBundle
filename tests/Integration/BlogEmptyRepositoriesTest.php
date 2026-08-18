<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Nowo\BlogKitBundle\Repository\BlogArticleResourceRepository;
use Nowo\BlogKitBundle\Repository\BlogArticleTranslationRepository;
use Nowo\BlogKitBundle\Repository\BlogTagTranslationRepository;
use PHPUnit\Framework\Attributes\Test;

final class BlogEmptyRepositoriesTest extends DoctrineTestCase
{
    #[Test]
    public function constructorOnlyRepositoriesCanBeInstantiatedWithTheTestRegistry(): void
    {
        $resourceRepository           = new BlogArticleResourceRepository($this->registry);
        $articleTranslationRepository = new BlogArticleTranslationRepository($this->registry);
        $tagTranslationRepository     = new BlogTagTranslationRepository($this->registry);

        self::assertSame(0, $resourceRepository->count([]));
        self::assertSame(0, $articleTranslationRepository->count([]));
        self::assertSame(0, $tagTranslationRepository->count([]));
    }
}
