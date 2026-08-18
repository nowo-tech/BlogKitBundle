<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogTagRegistry;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogTagRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function itSyncsDefinitionsAndResolvesExistingSlugs(): void
    {
        $existing = (new BlogTag())->setSlug('php');
        $existing->ensureTranslations();

        $repo = $this->createMock(BlogTagRepository::class);
        $repo->method('findBySlug')->willReturnCallback(
            static fn (string $slug): ?BlogTag => $slug === 'php' ? $existing : null,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::once())->method('flush');

        $registry = new BlogTagRegistry($em, $repo, LocaleTestSupport::create());
        $registry->syncDefinitions([
            'php'     => ['es' => 'PHP', 'en' => 'PHP'],
            'symfony' => ['es' => 'Symfony', 'en' => 'Symfony'],
        ]);

        self::assertSame('PHP', $existing->getTranslationOrFallback('es')->getName());
        self::assertSame([$existing], $registry->resolveSlugs(['php', 'missing']));
        self::assertSame([], $registry->resolveSlugs([]));
    }
}
