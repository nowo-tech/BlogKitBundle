<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Ui;

use Nowo\UiKitBundle\View\Label;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use Twig\Environment;

use function is_string;
use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layouts the pages extend
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; `web_ui.public_layout_template`, null =
 * `nowo_ui_kit.layouts.guest`), wraps a 1.x layout (no UiKit canonical blocks) in a deprecated bridge, and resolves
 * the per-page template overrides (`web_ui.templates.<page>`). Configuration only: no request state, safe in worker
 * mode.
 */
final readonly class BlogKitPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoBlogKitBundle`). */
    public const string BUNDLE = 'NowoBlogKitBundle';

    /** Translation domain of the bundle labels. */
    public const string DOMAIN = 'NowoBlogKitBundle';

    /** BC bridge around a 1.x admin layout that renders `{% block admin %}` (removed in 2.0). */
    public const string LEGACY_ADMIN_BRIDGE = '@NowoBlogKitBundle/admin/_legacy_layout.html.twig';

    /** BC bridge around a 1.x public layout that renders `{% block body %}` (removed in 2.0). */
    public const string LEGACY_PUBLIC_BRIDGE = '@NowoBlogKitBundle/public/_legacy_layout.html.twig';

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const string CANONICAL_BLOCK = 'nowo_ui_page_header';

    /** Canonical blocks a 1.x layout may already render (the bridges skip them). */
    private const array LEGACY_HOST_BLOCKS = ['nowo_ui_flashes', 'nowo_ui_content', 'nowo_ui_modals', 'nowo_ui_styles', 'nowo_ui_scripts'];

    /**
     * @param array<string, string> $templates `web_ui.templates` (page id => template)
     */
    public function __construct(
        #[Autowire('%nowo_blog_kit.web_ui.layout_template%')]
        private ?string $layoutTemplate = null,
        #[Autowire('%nowo_blog_kit.web_ui.public_layout_template%')]
        private ?string $publicLayoutTemplate = null,
        #[Autowire('%nowo_blog_kit.web_ui.templates%')]
        private array $templates = [],
        #[Autowire('@?twig')]
        private ?Environment $twig = null,
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page, string $default): string
    {
        $override = $this->templates[$page] ?? null;

        return is_string($override) && $override !== '' ? $override : $default;
    }

    /**
     * Layout the admin scaffolds extend (`page.layout`): `web_ui.layout_template`, the legacy bridge for a 1.x layout,
     * else null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        return $this->resolve($this->layoutTemplate, self::LEGACY_ADMIN_BRIDGE);
    }

    /**
     * Layout the public pages extend: `web_ui.public_layout_template`, the legacy bridge for a 1.x layout, else null
     * (UiKit `nowo_ui_layout.guest`).
     */
    public function publicLayout(): ?string
    {
        return $this->resolve($this->publicLayoutTemplate, self::LEGACY_PUBLIC_BRIDGE);
    }

    /**
     * Shared variables of the admin pages (added to the controller variables).
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        $legacy = $this->legacy($this->layoutTemplate);

        return $vars + [
            'nowo_blog_kit_legacy_layout' => $legacy,
            'nowo_blog_kit_legacy_blocks' => $this->hostBlocks($legacy),
        ];
    }

    /**
     * Shared variables of the public pages (added to the controller variables).
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function publicContext(array $vars): array
    {
        $legacy = $this->legacy($this->publicLayoutTemplate);

        return $vars + [
            'nowo_blog_kit_page_layout'          => $legacy !== null ? self::LEGACY_PUBLIC_BRIDGE : $this->publicLayoutTemplate,
            'nowo_blog_kit_legacy_public_layout' => $legacy,
            'nowo_blog_kit_legacy_blocks'        => $this->hostBlocks($legacy),
        ];
    }

    /**
     * Canonical blocks a 1.x layout defines itself (the bridge does not render them a second time).
     *
     * @return list<string>
     */
    private function hostBlocks(?string $legacy): array
    {
        if ($legacy === null || !$this->twig instanceof Environment) {
            return [];
        }

        $template = $this->twig->load($legacy);
        $context  = $this->twig->mergeGlobals([]);
        $blocks   = [];
        foreach (self::LEGACY_HOST_BLOCKS as $block) {
            if ($template->hasBlock($block, $context)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    private function resolve(?string $layout, string $bridge): ?string
    {
        if ($layout === null || $layout === '') {
            return null;
        }

        return $this->legacy($layout) !== null ? $bridge : $layout;
    }

    /**
     * The configured layout when it is a 1.x layout (it does not define the UiKit canonical blocks), else null.
     */
    private function legacy(?string $layout): ?string
    {
        if ($layout === null || $layout === '' || !$this->twig instanceof Environment) {
            return null;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            return null;
        }

        if ($canonical) {
            return null;
        }

        trigger_deprecation('nowo-tech/blog-kit-bundle', '1.7', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped in a deprecated bridge. Render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig / guest.html.twig) or set the layout to null to use nowo_ui_kit.layouts; see docs/UPGRADING.md.', $layout);

        return $layout;
    }
}
