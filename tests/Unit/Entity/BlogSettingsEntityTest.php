<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Entity;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use PHPUnit\Framework\TestCase;

final class BlogSettingsEntityTest extends TestCase
{
    public function testDefaultsAndPublicArray(): void
    {
        $settings = new BlogSettings();

        self::assertNull($settings->getId());
        self::assertSame('inherit', $settings->getListingMode());
        self::assertSame(BlogListingMode::Paginated, $settings->listingMode());
        self::assertSame('inherit', $settings->getMasonryStrategy());
        self::assertSame(BlogMasonryStrategy::Masonry, $settings->masonryStrategy());
        self::assertSame(6, $settings->getPerPage());
        self::assertSame(0, $settings->getMasonryColumnsMobile());
        self::assertSame(0, $settings->getMasonryColumnsTablet());
        self::assertSame(0, $settings->getMasonryColumnsDesktop());
        self::assertTrue($settings->isShowCardImage());
        self::assertTrue($settings->isShowComments());
        self::assertSame(BlogHeroImageMode::Contain->value, $settings->getHeroImageMode());
        self::assertSame(BlogAsidePlacement::Right, $settings->indexAsideSearch());

        $public = $settings->toPublicArray();
        self::assertSame('inherit', $public['listing_mode']);
        self::assertSame('inherit', $public['masonry_strategy']);
        self::assertSame(0, $public['masonry_columns_mobile']);
        self::assertSame(6, $public['per_page']);
        self::assertTrue($public['show_share']);
        self::assertSame('contain', $public['hero_image_mode']);
        self::assertSame('inherit', $public['comment_rate_limit_strategy']);
        self::assertSame(0, $public['comment_rate_limit_limit']);
        self::assertSame('inherit', $public['html_sanitize_strategy']);
    }

    public function testSettersClampAndNormalize(): void
    {
        $settings = new BlogSettings();
        $settings
            ->setListingMode('infinite')
            ->setListingMode('invalid')
            ->setPerPage(0)
            ->setPerPage(100)
            ->setMasonryStrategy('grid')
            ->setMasonryStrategy('invalid')
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
            ->setHeroImageMode('unknown')
            ->setCommentRateLimitStrategy('sliding_window')
            ->setCommentRateLimitStrategy('service')
            ->setCommentRateLimitStrategy('nope')
            ->setCommentRateLimitLimit(-1)
            ->setCommentRateLimitLimit(5000)
            ->setCommentRateLimitIntervalSeconds(-5)
            ->setCommentRateLimitIntervalSeconds(90000)
            ->setCommentCaptchaStrategy('honeypot')
            ->setHtmlSanitizeStrategy('allowlist')
            ->setHtmlSanitizeStrategy('');

        self::assertSame('inherit', $settings->getListingMode());
        self::assertSame('inherit', $settings->getMasonryStrategy());
        self::assertSame(24, $settings->getPerPage());
        self::assertSame(24, $settings->setPerPage(24)->getPerPage());
        self::assertSame(2, $settings->getMasonryColumnsMobile());
        self::assertSame(2, $settings->getMasonryColumnsTablet());
        self::assertSame(3, $settings->getMasonryColumnsDesktop());
        self::assertSame(0, $settings->setMasonryColumnsMobile(0)->getMasonryColumnsMobile());
        self::assertSame(0, $settings->setMasonryColumnsTablet(0)->getMasonryColumnsTablet());
        self::assertSame(0, $settings->setMasonryColumnsDesktop(0)->getMasonryColumnsDesktop());
        self::assertSame('grid', $settings->setMasonryStrategy('grid')->getMasonryStrategy());
        self::assertSame(BlogMasonryStrategy::Grid, $settings->masonryStrategy());
        self::assertSame('list', $settings->setMasonryStrategy('list')->getMasonryStrategy());
        self::assertSame(BlogMasonryStrategy::List, $settings->masonryStrategy());
        self::assertSame('masonry', $settings->setMasonryStrategy('masonry')->getMasonryStrategy());
        self::assertSame(BlogMasonryStrategy::Masonry, $settings->masonryStrategy());
        self::assertSame('inherit', $settings->setMasonryStrategy('inherit')->getMasonryStrategy());
        self::assertSame('inherit', $settings->setMasonryStrategy('')->getMasonryStrategy());
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
        self::assertSame('inherit', $settings->getCommentRateLimitStrategy());
        self::assertSame(1000, $settings->getCommentRateLimitLimit());
        self::assertSame(86400, $settings->getCommentRateLimitIntervalSeconds());
        self::assertSame('honeypot', $settings->getCommentCaptchaStrategy());
        self::assertSame('inherit', $settings->getHtmlSanitizeStrategy());
        self::assertSame('inherit', $settings->setCommentRateLimitStrategy('inherit')->getCommentRateLimitStrategy());
        self::assertSame(
            'sliding_window',
            $settings->setCommentRateLimitStrategy('sliding_window')->getCommentRateLimitStrategy(),
        );
        self::assertSame('infinite', $settings->setListingMode('infinite')->getListingMode());
        self::assertSame('inherit', $settings->setListingMode('inherit')->getListingMode());
    }
}
