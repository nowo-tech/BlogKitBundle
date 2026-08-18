<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
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
        self::assertSame(BlogMasonryStrategy::Masonry, $this->provider->masonryStrategy());
        self::assertSame(['mobile' => 1, 'tablet' => 2, 'desktop' => 2], $this->provider->masonryColumns());
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
            'listing_mode'            => 'legacy',
            'masonry_strategy'        => 'waterfall',
            'masonry_columns_mobile'  => 9,
            'masonry_columns_tablet'  => 9,
            'masonry_columns_desktop' => 9,
            'per_page'                => 30,
            'index_latest_limit'      => 0,
            'related_limit'           => 0,
            'index_tags_limit'        => 999,
            'index_aside_tags_limit'  => 999,
            'hero_image_mode'         => 'zoom',
            'show_aside_search'       => 'sideways',
            'show_comments'           => null,
        ]);
        $provider = $this->createProvider($invalidSettings);

        self::assertSame(BlogListingMode::Paginated, $provider->listingMode());
        self::assertSame(BlogMasonryStrategy::Masonry, $provider->masonryStrategy());
        self::assertSame(['mobile' => 2, 'tablet' => 2, 'desktop' => 3], $provider->masonryColumns());
        self::assertSame(24, $provider->perPage());
        self::assertSame(1, $provider->indexLatestLimit());
        self::assertSame(1, $provider->relatedLimit());
        self::assertSame(100, $provider->indexTagsLimit());
        self::assertSame(100, $provider->indexAsideTagsLimit());
        self::assertSame(BlogHeroImageMode::Contain, $provider->heroImageMode());
        self::assertSame(BlogAsidePlacement::Right, $provider->placement('show_aside_search'));
        self::assertFalse($provider->bool('show_comments', false));
    }

    #[Test]
    public function inheritAndBlankListingModeUseYamlDefault(): void
    {
        $inherit = $this->createProvider(new BlogSettings(), 'infinite');
        self::assertSame(BlogListingMode::Infinite, $inherit->listingMode());

        $blank = $this->createSettingsFromArray(['listing_mode' => '', 'per_page' => 6]);
        self::assertSame(BlogListingMode::Paginated, $this->createProvider($blank)->listingMode());

        $yamlInvalid = $this->createProvider(new BlogSettings(), 'nope');
        self::assertSame(BlogListingMode::Paginated, $yamlInvalid->listingMode());
    }

    #[Test]
    public function inheritAndBlankMasonryUseYamlDefaults(): void
    {
        $inherit = $this->createProvider(new BlogSettings(), 'paginated', 'grid', 2, 2, 3);
        self::assertSame(BlogMasonryStrategy::Grid, $inherit->masonryStrategy());
        self::assertSame(['mobile' => 2, 'tablet' => 2, 'desktop' => 3], $inherit->masonryColumns());

        $blank = $this->createSettingsFromArray([
            'masonry_strategy'        => '',
            'masonry_columns_mobile'  => 0,
            'masonry_columns_tablet'  => 0,
            'masonry_columns_desktop' => 0,
            'per_page'                => 6,
        ]);
        self::assertSame(BlogMasonryStrategy::Masonry, $this->createProvider($blank)->masonryStrategy());
        self::assertSame(['mobile' => 1, 'tablet' => 2, 'desktop' => 2], $this->createProvider($blank)->masonryColumns());

        $yamlInvalid = $this->createProvider(new BlogSettings(), 'paginated', 'nope', 0, 0, 0);
        self::assertSame(BlogMasonryStrategy::Masonry, $yamlInvalid->masonryStrategy());
        self::assertSame(['mobile' => 1, 'tablet' => 1, 'desktop' => 1], $yamlInvalid->masonryColumns());

        $override = $this->createSettingsFromArray([
            'masonry_strategy'        => 'list',
            'masonry_columns_mobile'  => 2,
            'masonry_columns_tablet'  => 1,
            'masonry_columns_desktop' => 3,
            'per_page'                => 6,
        ]);
        $overridden = $this->createProvider($override, 'paginated', 'grid', 1, 2, 2);
        self::assertSame(BlogMasonryStrategy::List, $overridden->masonryStrategy());
        self::assertSame(['mobile' => 2, 'tablet' => 1, 'desktop' => 3], $overridden->masonryColumns());
    }

    private function createProvider(
        BlogSettings $settings,
        string $listingModeDefault = 'paginated',
        string $masonryStrategyDefault = 'masonry',
        int $masonryColumnsMobileDefault = 1,
        int $masonryColumnsTabletDefault = 2,
        int $masonryColumnsDesktopDefault = 2,
    ): BlogSettingsProvider {
        return new BlogSettingsProvider(
            RepositoryTestSupport::blogSettingsRepository($settings),
            $listingModeDefault,
            $masonryStrategyDefault,
            $masonryColumnsMobileDefault,
            $masonryColumnsTabletDefault,
            $masonryColumnsDesktopDefault,
        );
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
