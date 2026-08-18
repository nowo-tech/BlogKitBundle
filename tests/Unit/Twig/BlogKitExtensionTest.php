<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Twig;

use Nowo\BlogKitBundle\Enum\CssFramework;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Twig\BlogKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlogKitExtensionTest extends TestCase
{
    #[Test]
    public function getGlobalsReturnsTemplateLocaleAndAccessFlags(): void
    {
        $extension = $this->createExtension();

        self::assertSame([
            'nowo_blog_kit_layout'              => '@NowoBlogKitBundle/admin/layout.html.twig',
            'nowo_blog_kit_public_layout'       => '@NowoBlogKitBundle/public/layout.html.twig',
            'nowo_blog_kit_css_framework'       => 'tailwind',
            'nowo_blog_kit_default_locale'      => 'es',
            'nowo_blog_kit_locales'             => ['es', 'en'],
            'nowo_blog_kit_privacy_url'         => '/privacy',
            'nowo_blog_kit_icon_set'            => 'bootstrap-icons',
            'nowo_blog_kit_row_actions_display' => 'icon',
            'nowo_blog_kit_can_manage'          => true,
            'nowo_blog_kit_can_moderate'        => false,
            'nowo_blog_kit_can_configure'       => true,
        ], $extension->getGlobals());
    }

    #[Test]
    public function getFunctionsExposesContainerClassHelper(): void
    {
        $extension = $this->createExtension();
        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('nowo_blog_kit_container_class', $functions[0]->getName());
        self::assertSame('blog-container', $extension->containerClass());
    }

    #[Test]
    #[DataProvider('provideContainerClassByFramework')]
    public function containerClassAddsHostFrameworkWrapper(string $framework, string $expected): void
    {
        $extension = $this->createExtension($framework);

        self::assertSame($expected, $extension->containerClass());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideContainerClassByFramework(): iterable
    {
        yield 'bootstrap' => [CssFramework::Bootstrap->value, 'blog-container container'];
        yield 'bootstrap4' => [CssFramework::Bootstrap4->value, 'blog-container container'];
        yield 'bootstrap5' => [CssFramework::Bootstrap5->value, 'blog-container container'];
        yield 'tabler' => [CssFramework::Tabler->value, 'blog-container container'];
        yield 'foundation' => [CssFramework::Foundation->value, 'blog-container grid-container'];
        yield 'tailwind' => [CssFramework::Tailwind->value, 'blog-container'];
        yield 'custom' => [CssFramework::Custom->value, 'blog-container'];
        yield 'none' => [CssFramework::None->value, 'blog-container'];
        yield 'unknown' => ['not-a-framework', 'blog-container'];
    }

    private function createExtension(string $cssFramework = 'tailwind'): BlogKitExtension
    {
        $accessChecker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $accessChecker->method('canManage')->willReturn(true);
        $accessChecker->method('canModerate')->willReturn(false);
        $accessChecker->method('canConfigure')->willReturn(true);

        return new BlogKitExtension(
            '@NowoBlogKitBundle/admin/layout.html.twig',
            '@NowoBlogKitBundle/public/layout.html.twig',
            $cssFramework,
            'es',
            ['es', 'en'],
            '/privacy',
            $accessChecker,
        );
    }
}
