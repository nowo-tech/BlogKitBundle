<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Twig;

use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig globals for blog kit layouts and access flags.
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
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'nowo_blog_kit_layout'         => $this->layoutTemplate,
            'nowo_blog_kit_public_layout'  => $this->publicLayoutTemplate,
            'nowo_blog_kit_css_framework'  => $this->cssFramework,
            'nowo_blog_kit_default_locale' => $this->defaultLocale,
            'nowo_blog_kit_locales'        => $this->locales,
            'nowo_blog_kit_privacy_url'    => $this->privacyUrl,
            'nowo_blog_kit_can_manage'     => $this->accessChecker->canManage(),
            'nowo_blog_kit_can_moderate'   => $this->accessChecker->canModerate(),
            'nowo_blog_kit_can_configure'  => $this->accessChecker->canConfigure(),
        ];
    }
}
