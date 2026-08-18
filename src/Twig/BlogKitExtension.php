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
        private readonly string $layoutTemplate,
        private readonly string $publicLayoutTemplate,
        private readonly string $cssFramework,
        private readonly string $defaultLocale,
        private readonly array $locales,
        private readonly string $privacyUrl,
        private readonly BlogKitAccessCheckerInterface $accessChecker,
        private readonly string $iconSet = 'bootstrap-icons',
        private readonly string $rowActionsDisplay = 'icon',
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
        ];
    }

    /**
     * Semantic width wrapper plus the host framework container class when it exists.
     *
     * Hosts still load Bootstrap / Foundation / Tailwind themselves; this only adds
     * compatible class names so public templates stay fork-free (REQ-UI-001).
     */
    public function containerClass(): string
    {
        $framework = CssFramework::tryFrom($this->cssFramework) ?? CssFramework::Custom;

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
        return $this->protection?->captcha()->twigContext() ?? [
            'enabled'      => false,
            'strategy'     => 'none',
            'site_key'     => '',
            'script_url'   => '',
            'widget_class' => '',
        ];
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
            'nowo_blog_kit_can_manage'          => $this->accessChecker->canManage(),
            'nowo_blog_kit_can_moderate'        => $this->accessChecker->canModerate(),
            'nowo_blog_kit_can_configure'       => $this->accessChecker->canConfigure(),
        ];
    }
}
