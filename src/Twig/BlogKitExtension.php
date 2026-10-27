<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Twig;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Enum\CssFramework;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig globals and markup helpers for blog kit layouts and access flags.
 */
final class BlogKitExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        private readonly ?string $layoutTemplate,
        private readonly ?string $publicLayoutTemplate,
        private readonly ?string $cssFramework,
        private readonly string $defaultLocale,
        private readonly array $locales,
        private readonly string $privacyUrl,
        private readonly BlogKitAccessCheckerInterface $accessChecker,
        private readonly ?string $iconSet = null,
        private readonly ?string $rowActionsDisplay = null,
        private readonly ?BlogProtection $protection = null,
        private readonly ?BlogKitResourceAccessCheckerInterface $resourceAccess = null,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_blog_kit_container_class', $this->containerClass(...)),
            new TwigFunction('nowo_blog_kit_captcha', $this->captchaContext(...)),
            new TwigFunction('nowo_blog_kit_can_manage_article', $this->canManageArticle(...)),
            new TwigFunction('nowo_blog_kit_can_manage_tag', $this->canManageTag(...)),
            new TwigFunction('nowo_blog_kit_can_moderate_comment', $this->canModerateComment(...)),
            new TwigFunction('nowo_blog_kit_can_manage', $this->canManage(...)),
            new TwigFunction('nowo_blog_kit_can_moderate', $this->canModerate(...)),
            new TwigFunction('nowo_blog_kit_can_configure', $this->canConfigure(...)),
        ];
    }

    public function canManage(): bool
    {
        return $this->accessChecker->canManage();
    }

    public function canModerate(): bool
    {
        return $this->accessChecker->canModerate();
    }

    public function canConfigure(): bool
    {
        return $this->accessChecker->canConfigure();
    }

    /**
     * Semantic width wrapper plus the host framework container class when it exists.
     *
     * Deprecated since 1.7: the bundle pages no longer call it (REQ-UI-003: no framework classes in bundle markup;
     * the page width comes from the UiKit layout). Kept for host templates; returns `blog-container` unless the
     * deprecated `web_ui.css_framework` is set.
     */
    public function containerClass(): string
    {
        $framework = CssFramework::tryFrom((string) $this->cssFramework) ?? CssFramework::Custom;

        return match ($framework) {
            CssFramework::Bootstrap,
            CssFramework::Bootstrap4,
            CssFramework::Bootstrap5,
            CssFramework::Tabler     => 'blog-container container',
            CssFramework::Foundation => 'blog-container grid-container',
            CssFramework::Tailwind,
            CssFramework::Custom,
            CssFramework::None => 'blog-container',
        };
    }

    /**
     * @return array{enabled: bool, strategy: string, site_key: string, script_url: string, widget_class: string}
     */
    public function captchaContext(): array
    {
        if (!$this->protection instanceof BlogProtection) {
            return ['enabled' => false, 'strategy' => 'none', 'site_key' => '', 'script_url' => '', 'widget_class' => ''];
        }

        return $this->protection->captcha()->twigContext();
    }

    public function canManageArticle(BlogArticle $article): bool
    {
        return $this->resourceAccess?->canManageArticle($article) ?? true;
    }

    public function canManageTag(BlogTag $tag): bool
    {
        return $this->resourceAccess?->canManageTag($tag) ?? true;
    }

    public function canModerateComment(BlogComment $comment): bool
    {
        return $this->resourceAccess?->canModerateComment($comment) ?? true;
    }

    /**
     * Deprecated since 1.7 (REQ-UI-003): `nowo_blog_kit_layout` / `nowo_blog_kit_public_layout` (the pages resolve the
     * layout through BlogKitPages), `nowo_blog_kit_css_framework` / `_icon_set` / `_row_actions_display` (null unless
     * the deprecated `web_ui.*` keys are set; read `nowo_ui_kit_*`).
     *
     * The `nowo_blog_kit_can_*` globals are deprecated: Twig resolves globals once per environment,
     * so in long-running workers without `services_resetter` they keep the first user's flags.
     * Use the `nowo_blog_kit_can_manage()` / `_moderate()` / `_configure()` functions instead.
     */
    public function getGlobals(): array
    {
        return [
            'nowo_blog_kit_layout'              => $this->layoutTemplate,
            'nowo_blog_kit_public_layout'       => $this->publicLayoutTemplate,
            'nowo_blog_kit_css_framework'       => $this->cssFramework,
            'nowo_blog_kit_default_locale'      => $this->defaultLocale,
            'nowo_blog_kit_locales'             => $this->locales,
            'nowo_blog_kit_privacy_url'         => $this->privacyUrl,
            'nowo_blog_kit_icon_set'            => $this->iconSet,
            'nowo_blog_kit_row_actions_display' => $this->rowActionsDisplay,
            // Fail closed (SEC-004 I6): a global is computed once per Twig environment, so in a worker it would keep
            // the first user's flags. The functions nowo_blog_kit_can_*() are evaluated per call.
            'nowo_blog_kit_can_manage'    => false,
            'nowo_blog_kit_can_moderate'  => false,
            'nowo_blog_kit_can_configure' => false,
        ];
    }
}
