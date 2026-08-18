<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Tests\Support\RepositoryTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogSettingsProviderTest extends TestCase
{
    private BlogSettings $settings;
    private BlogSettingsProvider $provider;

    protected function setUp(): void
    {
        $this->settings = (new BlogSettings())
            ->setListingMode('infinite')
            ->setPerPage(12)
            ->setIndexLatestLimit(8)
            ->setRelatedLimit(3)
            ->setIndexTagsLimit(15)
            ->setIndexAsideTagsLimit(10)
            ->setShowAsideSearch('left')
            ->setShowAsideRelated('both')
            ->setShowAsideArticleTags('off')
            ->setHeroImageMode('cover')
            ->setShowShare(false);

        $this->provider = $this->createProvider($this->settings);
    }

    #[Test]
    public function itCachesSettingsArrayAndCanReset(): void
    {
        $all = $this->provider->all();

        self::assertSame('infinite', $all['listing_mode']);
        self::assertSame($all, $this->provider->all());

        $this->provider->reset();

        self::assertSame('infinite', $this->provider->all()['listing_mode']);
    }

    #[Test]
    public function itExposesTheUnderlyingSettingsEntity(): void
    {
        self::assertSame($this->settings, $this->provider->settings());
    }

    #[Test]
    public function itReturnsTypedAccessorsAndAsideSlots(): void
    {
        self::assertSame(BlogListingMode::Infinite, $this->provider->listingMode());
        self::assertSame(12, $this->provider->perPage());
        self::assertSame(8, $this->provider->indexLatestLimit());
        self::assertSame(3, $this->provider->relatedLimit());
        self::assertSame(15, $this->provider->indexTagsLimit());
        self::assertSame(10, $this->provider->indexAsideTagsLimit());
        self::assertSame(BlogHeroImageMode::Cover, $this->provider->heroImageMode());
        self::assertSame(BlogAsidePlacement::Left, $this->provider->placement('show_aside_search'));
        self::assertSame(BlogAsidePlacement::Off, $this->provider->placement('show_aside_article_tags'));
        self::assertFalse($this->provider->bool('show_share'));
        self::assertTrue($this->provider->bool('missing_key'));
        self::assertFalse($this->provider->bool('missing_key', false));

        $slots = $this->provider->asideSlots([
            'search'  => 'show_aside_search',
            'related' => 'show_aside_related',
            'tags'    => 'show_aside_article_tags',
        ]);

        self::assertSame(['search', 'related'], $slots['left']);
        self::assertSame(['related'], $slots['right']);
    }

    #[Test]
    public function itFallsBackForInvalidEnums(): void
    {
        $invalidSettings = $this->createSettingsFromArray([
            'listing_mode'           => 'legacy',
            'per_page'               => 30,
            'index_latest_limit'     => 0,
            'related_limit'          => 0,
            'index_tags_limit'       => 999,
            'index_aside_tags_limit' => 999,
            'hero_image_mode'        => 'zoom',
            'show_aside_search'      => 'sideways',
            'show_comments'          => null,
        ]);
        $provider = $this->createProvider($invalidSettings);

        self::assertSame(BlogListingMode::Paginated, $provider->listingMode());
        self::assertSame(24, $provider->perPage());
        self::assertSame(1, $provider->indexLatestLimit());
        self::assertSame(1, $provider->relatedLimit());
        self::assertSame(100, $provider->indexTagsLimit());
        self::assertSame(100, $provider->indexAsideTagsLimit());
        self::assertSame(BlogHeroImageMode::Contain, $provider->heroImageMode());
        self::assertSame(BlogAsidePlacement::Right, $provider->placement('show_aside_search'));
        self::assertFalse($provider->bool('show_comments', false));
    }

    private function createProvider(BlogSettings $settings): BlogSettingsProvider
    {
        return new BlogSettingsProvider(RepositoryTestSupport::blogSettingsRepository($settings));
    }

    /** @param array<string, mixed> $values */
    private function createSettingsFromArray(array $values): BlogSettings
    {
        return new class($values) extends BlogSettings {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function toPublicArray(): array
            {
                return $this->values;
            }
        };
    }
}
