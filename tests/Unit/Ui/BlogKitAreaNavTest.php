<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Ui;

use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Tests\Support\PageFixtures;
use Nowo\BlogKitBundle\Ui\BlogKitAreaNav;
use Nowo\BlogKitBundle\Ui\BlogKitPages;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

final class BlogKitAreaNavTest extends TestCase
{
    #[Test]
    public function adminAreaListsEverySectionForAFullAdmin(): void
    {
        $nav = $this->provider(true, true)->areaNav(BlogKitAreaNav::AREA);

        self::assertInstanceOf(AreaNav::class, $nav);
        self::assertSame(['/admin_blog_index', '/admin_blog_tags_index', '/admin_blog_comments_index', '/admin_blog_settings'], $this->hrefs($nav));
        self::assertSame(['admin.articles.title', 'admin.tags.title', 'admin.comments.title', 'admin.settings.title'], $this->labels($nav));
        self::assertEquals(new Label('admin.area_nav.label', BlogKitPages::DOMAIN), $nav->label);
        self::assertSame(['data-testid' => 'blog-kit-nav-articles'], $nav->items[0]->attributes);
        self::assertNull($nav->active());
    }

    #[Test]
    public function adminAreaHidesCommentsAndSettingsWithoutTheCapabilities(): void
    {
        $nav = $this->provider(false, false)->areaNav(BlogKitAreaNav::AREA, ['current' => '/admin_blog_tags_index']);

        self::assertInstanceOf(AreaNav::class, $nav);
        self::assertSame(['/admin_blog_index', '/admin_blog_tags_index'], $this->hrefs($nav));
        self::assertSame('/admin_blog_tags_index', $nav->active()?->href);
    }

    #[Test]
    public function settingsAreaListsTheSettingsSections(): void
    {
        $nav = $this->provider(true, true)->areaNav(BlogKitAreaNav::AREA_SETTINGS, ['current' => '']);

        self::assertInstanceOf(AreaNav::class, $nav);
        self::assertSame(['/admin_blog_settings_listing', '/admin_blog_settings_cards', '/admin_blog_settings_index_aside', '/admin_blog_settings_article', '/admin_blog_settings_comments'], $this->hrefs($nav));
        self::assertSame(['data-testid' => 'blog-kit-nav-settings-index-aside'], $nav->items[2]->attributes);
        self::assertNull($nav->active());
    }

    #[Test]
    public function unknownAreaIsNotProvided(): void
    {
        self::assertNull($this->provider(true, true)->areaNav('other', ['current' => '/admin_blog_index']));
    }

    private function provider(bool $moderate, bool $configure): BlogKitAreaNav
    {
        $checker = $this->createStub(BlogKitAccessCheckerInterface::class);
        $checker->method('canModerate')->willReturn($moderate);
        $checker->method('canConfigure')->willReturn($configure);

        return new BlogKitAreaNav(PageFixtures::urlGenerator(), $checker);
    }

    /** @return list<string> */
    private function hrefs(AreaNav $nav): array
    {
        return array_map(static fn (AreaNavItem $item): string => $item->href, $nav->items);
    }

    /** @return list<string> */
    private function labels(AreaNav $nav): array
    {
        return array_map(static fn (AreaNavItem $item): string => $item->label instanceof Label ? $item->label->key : $item->label, $nav->items);
    }
}
