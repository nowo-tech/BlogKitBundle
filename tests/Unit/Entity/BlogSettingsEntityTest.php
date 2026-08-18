<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Entity;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use PHPUnit\Framework\TestCase;

final class BlogSettingsEntityTest extends TestCase
{
    public function testDefaultsAndPublicArray(): void
    {
        $settings = new BlogSettings();

        self::assertNull($settings->getId());
        self::assertSame(BlogListingMode::Paginated->value, $settings->getListingMode());
        self::assertSame(BlogListingMode::Paginated, $settings->listingMode());
        self::assertSame(6, $settings->getPerPage());
        self::assertTrue($settings->isShowCardImage());
        self::assertTrue($settings->isShowComments());
        self::assertSame(BlogHeroImageMode::Contain->value, $settings->getHeroImageMode());
        self::assertSame(BlogAsidePlacement::Right, $settings->indexAsideSearch());

        $public = $settings->toPublicArray();
        self::assertSame('paginated', $public['listing_mode']);
        self::assertSame(6, $public['per_page']);
        self::assertTrue($public['show_share']);
        self::assertSame('contain', $public['hero_image_mode']);
    }

    public function testSettersClampAndNormalize(): void
    {
        $settings = new BlogSettings();
        $settings
            ->setListingMode('infinite')
            ->setListingMode('invalid')
            ->setPerPage(0)
            ->setPerPage(100)
            ->setMasonryColumnsMobile(0)
            ->setMasonryColumnsMobile(5)
            ->setMasonryColumnsTablet(99)
            ->setMasonryColumnsDesktop(0)
            ->setMasonryColumnsDesktop(10)
            ->setIndexTagsLimit(-5)
            ->setIndexTagsLimit(200)
            ->setIndexLatestLimit(0)
            ->setIndexLatestLimit(50)
            ->setIndexAsideTagsLimit(-1)
            ->setRelatedLimit(0)
            ->setRelatedLimit(99)
            ->setShowCardImage(false)
            ->setShowCardExcerpt(false)
            ->setShowCardTags(false)
            ->setIndexAsideSearch('left')
            ->setIndexAsideLatest('both')
            ->setIndexAsideTags('off')
            ->setShowAsideSearch('invalid')
            ->setShowAsideRelated('left')
            ->setShowAsideArticleTags('both')
            ->setShowAsideResources('off')
            ->setResourcesIncludeLinkedin(false)
            ->setShowShare(false)
            ->setShowComments(false)
            ->setShowSourceLink(false)
            ->setHeroImageMode('cover')
            ->setHeroImageMode('unknown');

        self::assertSame(BlogListingMode::Paginated->value, $settings->getListingMode());
        self::assertSame(24, $settings->getPerPage());
        self::assertSame(24, $settings->setPerPage(24)->getPerPage());
        self::assertSame(2, $settings->getMasonryColumnsMobile());
        self::assertSame(2, $settings->getMasonryColumnsTablet());
        self::assertSame(3, $settings->getMasonryColumnsDesktop());
        self::assertSame(100, $settings->getIndexTagsLimit());
        self::assertSame(100, $settings->setIndexTagsLimit(100)->getIndexTagsLimit());
        self::assertSame(24, $settings->getIndexLatestLimit());
        self::assertSame(24, $settings->setRelatedLimit(24)->getRelatedLimit());
        self::assertFalse($settings->isShowCardImage());
        self::assertSame('left', $settings->getIndexAsideSearch());
        self::assertSame(BlogAsidePlacement::Left, $settings->indexAsideSearch());
        self::assertSame(BlogAsidePlacement::Both, $settings->indexAsideLatest());
        self::assertSame(BlogAsidePlacement::Off, $settings->indexAsideTags());
        self::assertSame(BlogAsidePlacement::Right, $settings->showAsideSearch());
        self::assertSame(BlogAsidePlacement::Left, $settings->showAsideRelated());
        self::assertSame(BlogAsidePlacement::Both, $settings->showAsideArticleTags());
        self::assertSame(BlogAsidePlacement::Off, $settings->showAsideResources());
        self::assertFalse($settings->isResourcesIncludeLinkedin());
        self::assertFalse($settings->isShowShare());
        self::assertFalse($settings->isShowComments());
        self::assertFalse($settings->isShowSourceLink());
        self::assertSame(BlogHeroImageMode::Contain->value, $settings->getHeroImageMode());
        self::assertSame(BlogHeroImageMode::Cover, $settings->setHeroImageMode('cover')->heroImageMode());
    }
}
