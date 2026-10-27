<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Ui;

use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\UiKitBundle\Navigation\AreaNavProviderInterface;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_string;

/**
 * Publishes the blog admin navigation to the UiKit area-nav registry (REQ-UI-003 "Navigation integration"), so a host
 * menu (DashboardMenu) can mount it: `nowo_ui_area_nav('blog_kit')` (Articles | Tags | Comments | Settings, the last
 * two only for users who may moderate / configure) and `nowo_ui_area_nav('blog_kit_settings')` (the settings
 * sections, the same entries as the settings page tabs). `current` (an href) marks the active item. The admin pages
 * render the same navigation as page-header tabs (fallback for hosts without DashboardMenu). Stateless: safe in
 * worker mode.
 */
final readonly class BlogKitAreaNav implements AreaNavProviderInterface
{
    public const string AREA = 'blog_kit';

    public const string AREA_SETTINGS = 'blog_kit_settings';

    /** Settings sections (section id => route), in tab order. */
    public const array SETTINGS_SECTIONS = [
        'listing'     => 'admin_blog_settings_listing',
        'cards'       => 'admin_blog_settings_cards',
        'index-aside' => 'admin_blog_settings_index_aside',
        'article'     => 'admin_blog_settings_article',
        'comments'    => 'admin_blog_settings_comments',
    ];

    /** Translation keys of the settings sections (section id => key). */
    public const array SETTINGS_LABELS = [
        'listing'     => 'admin.settings.sections.listing',
        'cards'       => 'admin.settings.sections.cards',
        'index-aside' => 'admin.settings.sections.index_aside',
        'article'     => 'admin.settings.sections.article',
        'comments'    => 'admin.settings.sections.comments',
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private BlogKitAccessCheckerInterface $accessChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $context `current` (href of the active item)
     */
    public function areaNav(string $area, array $context = []): ?AreaNav
    {
        $nav = match ($area) {
            self::AREA          => $this->adminNav(),
            self::AREA_SETTINGS => $this->settingsNav(),
            default             => null,
        };

        $current = $context['current'] ?? null;

        return $nav instanceof AreaNav && is_string($current) && $current !== '' ? $nav->withActive($current) : $nav;
    }

    private function adminNav(): AreaNav
    {
        $items = [
            $this->item('admin.articles.title', 'admin_blog_index', 'articles'),
            $this->item('admin.tags.title', 'admin_blog_tags_index', 'tags'),
        ];
        if ($this->accessChecker->canModerate()) {
            $items[] = $this->item('admin.comments.title', 'admin_blog_comments_index', 'comments');
        }
        if ($this->accessChecker->canConfigure()) {
            $items[] = $this->item('admin.settings.title', 'admin_blog_settings', 'settings');
        }

        return new AreaNav($items, BlogKitPages::label('admin.area_nav.label'));
    }

    private function settingsNav(): AreaNav
    {
        $items = [];
        foreach (self::SETTINGS_SECTIONS as $id => $route) {
            $items[] = $this->item(self::SETTINGS_LABELS[$id], $route, 'settings-' . $id);
        }

        return new AreaNav($items, BlogKitPages::label('admin.settings.section_tabs_label'));
    }

    private function item(string $label, string $route, string $testId): AreaNavItem
    {
        return new AreaNavItem(BlogKitPages::label($label), $this->urlGenerator->generate($route), false, ['data-testid' => 'blog-kit-nav-' . $testId]);
    }
}
