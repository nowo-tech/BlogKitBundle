<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Ui;

use LogicException;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use Twig\Environment;

use function is_string;
use function sprintf;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layouts the pages extend
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; `web_ui.public_layout_template`, null =
 * `nowo_ui_kit.layouts.guest`) and the per-page template overrides (`web_ui.templates.<page>`). Configuration only:
 * no request state, safe in worker mode.
 *
 * Since 2.0 the deprecated 1.7 bridges around a 1.x layout (no UiKit canonical blocks) are removed: such a layout
 * fails clearly when the first page renders ({@see LogicException} naming it).
 */
final readonly class BlogKitPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoBlogKitBundle`). */
    public const string BUNDLE = 'NowoBlogKitBundle';

    /** Translation domain of the bundle labels. */
    public const string DOMAIN = 'NowoBlogKitBundle';

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const string CANONICAL_BLOCK = 'nowo_ui_page_header';

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
        // Autowired by type: `#[Autowire('@?twig')]` is not an optional reference (the id becomes "?twig").
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
     * Layout the admin scaffolds extend (`page.layout`): `web_ui.layout_template`, else null (UiKit
     * `nowo_ui_layout.admin`).
     *
     * @throws LogicException when the configured layout does not render the UiKit canonical blocks
     */
    public function layout(): ?string
    {
        return $this->resolve($this->layoutTemplate, 'web_ui.layout_template', '@NowoUiKit/layout/admin.html.twig');
    }

    /**
     * Layout the public pages extend: `web_ui.public_layout_template`, else null (UiKit `nowo_ui_layout.guest`).
     *
     * @throws LogicException when the configured layout does not render the UiKit canonical blocks
     */
    public function publicLayout(): ?string
    {
        return $this->resolve($this->publicLayoutTemplate, 'web_ui.public_layout_template', '@NowoUiKit/layout/guest.html.twig');
    }

    /**
     * Shared variables of the admin pages (added to the controller variables).
     *
     * Since 2.0 nothing is added (the 1.x bridge variables were removed); the layout is resolved by
     * {@see BlogKitPageFactory} through {@see layout()}.
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $vars;
    }

    /**
     * Shared variables of the public pages (added to the controller variables).
     *
     * @param array<string, mixed> $vars page variables
     *
     * @throws LogicException when `web_ui.public_layout_template` does not render the UiKit canonical blocks
     *
     * @return array<string, mixed>
     */
    public function publicContext(array $vars): array
    {
        return $vars + ['nowo_blog_kit_page_layout' => $this->publicLayout()];
    }

    private function resolve(?string $layout, string $key, string $uiKitLayout): ?string
    {
        if ($layout === null || $layout === '' || !$this->twig instanceof Environment) {
            return $layout === '' ? null : $layout;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            // A layout that does not load fails where the page extends it, with Twig's own message.
            return $layout;
        }

        if (!$canonical) {
            throw new LogicException(sprintf('The layout "%s" (nowo_blog_kit.%s) does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...). The 1.x layout bridge (deprecated since 1.7) was removed in 2.0: render the canonical blocks (or extend %s), or set the option to null to use nowo_ui_kit.layouts; see UPGRADING.md "To 2.0.0" and TEMPLATES.md.', $layout, $key, $uiKitLayout));
        }

        return $layout;
    }
}
