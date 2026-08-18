<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Twig;

use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Twig\BlogKitExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogKitExtensionTest extends TestCase
{
    #[Test]
    public function getGlobalsReturnsTemplateLocaleAndAccessFlags(): void
    {
        $accessChecker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $accessChecker->method('canManage')->willReturn(true);
        $accessChecker->method('canModerate')->willReturn(false);
        $accessChecker->method('canConfigure')->willReturn(true);

        $extension = new BlogKitExtension(
            '@NowoBlogKitBundle/admin/layout.html.twig',
            '@NowoBlogKitBundle/public/layout.html.twig',
            'tailwind',
            'es',
            ['es', 'en'],
            '/privacy',
            $accessChecker,
        );

        self::assertSame([
            'nowo_blog_kit_layout'         => '@NowoBlogKitBundle/admin/layout.html.twig',
            'nowo_blog_kit_public_layout'  => '@NowoBlogKitBundle/public/layout.html.twig',
            'nowo_blog_kit_css_framework'  => 'tailwind',
            'nowo_blog_kit_default_locale' => 'es',
            'nowo_blog_kit_locales'        => ['es', 'en'],
            'nowo_blog_kit_privacy_url'    => '/privacy',
            'nowo_blog_kit_can_manage'     => true,
            'nowo_blog_kit_can_moderate'   => false,
            'nowo_blog_kit_can_configure'  => true,
        ], $extension->getGlobals());
    }
}
