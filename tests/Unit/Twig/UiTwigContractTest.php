<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_exists;
use function file_get_contents;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Static REQ-UI-003 checks the rendered page contract test does not cover: no local copies of the UiKit chrome
 * partials, no own document layout, bundle assets stacked after parent(), the inline CMS dialog on the UiKit `dialog`
 * partial.
 */
final class UiTwigContractTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRemovedChrome')]
    public function bundleShipsNoLocalChromePartialOrLayout(string $relativePath): void
    {
        self::assertFileDoesNotExist($this->views() . '/' . $relativePath);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideRemovedChrome(): iterable
    {
        foreach ([
            'admin/layout.html.twig', 'admin/base.html.twig', 'public/layout.html.twig', 'public/base.html.twig',
            'admin/_pagination.html.twig', 'admin/_delete_confirm.html.twig', 'admin/_list_filters.html.twig',
            'admin/_row_actions.html.twig', 'admin/_nav_tabs.html.twig', 'admin/_area_nav.html.twig',
            'admin/_settings_section_tabs.html.twig', 'admin/_csrf_action_form.html.twig',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[Test]
    public function noTemplatePrintsADocumentSkeletonOrAWindowConfirm(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->views()));
        $checked  = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            $name    = substr($file->getPathname(), strlen($this->views()) + 1);
            self::assertDoesNotMatchRegularExpression('/<!doctype|<html[\s>]|<body[\s>]/i', $content, $name);
            self::assertDoesNotMatchRegularExpression('/\bconfirm\s*\(/', $content, $name);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*"/i', $content, $name);
            ++$checked;
        }
        self::assertGreaterThan(10, $checked);
    }

    #[Test]
    #[DataProvider('providePages')]
    public function pagesStackBundleAssetsAfterParent(string $relativePath, bool $scripts): void
    {
        $content = (string) file_get_contents($this->views() . '/' . $relativePath);
        self::assertMatchesRegularExpression('/\{% block nowo_ui_styles %\}\s*\{\{ parent\(\) \}\}\s*\{\{ include\(\'@NowoBlogKitBundle\/_stylesheets\.html\.twig\'\) \}\}/', $content, $relativePath);
        if ($scripts) {
            self::assertMatchesRegularExpression('/\{% block nowo_ui_scripts %\}\s*\{\{ parent\(\) \}\}\s*\{\{ include\(\'@NowoBlogKitBundle\/_scripts\.html\.twig\'\) \}\}/', $content, $relativePath);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function providePages(): iterable
    {
        yield 'articles' => ['admin/index.html.twig', false];
        yield 'article form' => ['admin/form.html.twig', true];
        yield 'tags' => ['admin/tags/index.html.twig', false];
        yield 'tag form' => ['admin/tags/form.html.twig', false];
        yield 'comments' => ['admin/comments/index.html.twig', false];
        yield 'settings' => ['admin/settings.html.twig', false];
        yield 'public index' => ['public/index.html.twig', true];
        yield 'public article' => ['public/show.html.twig', true];
    }

    #[Test]
    public function inlineCmsModalUsesTheUiKitDialogPartial(): void
    {
        $content = (string) file_get_contents($this->views() . '/admin/_modal_form.html.twig');
        self::assertStringContainsString("nowo_ui_partial('dialog', _b)", $content);
        self::assertStringContainsString('@NowoBlogKitBundle/admin/_form_body.html.twig', $content);
        self::assertStringNotContainsString('nowo-ui-modal__', $content);
    }

    #[Test]
    public function assetPartialsUseTheBundleAssetPackage(): void
    {
        self::assertTrue(file_exists($this->views() . '/_stylesheets.html.twig'));
        self::assertStringContainsString("asset('blog.css', 'nowo_blog_kit')", (string) file_get_contents($this->views() . '/_stylesheets.html.twig'));
        self::assertStringContainsString("asset('blog-kit.js', 'nowo_blog_kit')", (string) file_get_contents($this->views() . '/_scripts.html.twig'));
        self::assertSame(sprintf('%s/src/Resources/views', dirname(__DIR__, 3)), $this->views());
    }

    private function views(): string
    {
        return dirname(__DIR__, 3) . '/src/Resources/views';
    }
}
