<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function sprintf;

final class UiTwigContractTest extends TestCase
{
    #[Test]
    #[DataProvider('provideParentAssetBases')]
    public function baseTemplatesStackAssetsWithParentAndNestedBlocks(string $relativePath): void
    {
        $path    = dirname(__DIR__, 3) . '/src/Resources/views/' . $relativePath;
        $content = file_get_contents($path);
        self::assertNotFalse($content);
        self::assertStringContainsString('{{ parent() }}', $content);
        self::assertStringContainsString('{% block nowo_ui_styles %}', $content);
        self::assertStringContainsString('{% block nowo_ui_scripts %}', $content);
        self::assertStringContainsString("asset('blog.css', 'nowo_blog_kit')", $content);
        self::assertStringContainsString("asset('blog-kit.js', 'nowo_blog_kit')", $content);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideParentAssetBases(): iterable
    {
        yield 'admin' => ['admin/base.html.twig'];
        yield 'public' => ['public/base.html.twig'];
    }

    #[Test]
    public function inlineCmsModalUsesNativeDialogRegions(): void
    {
        $path    = dirname(__DIR__, 3) . '/src/Resources/views/admin/_modal_form.html.twig';
        $content = file_get_contents($path);
        self::assertNotFalse($content);
        self::assertStringContainsString('<dialog', $content);
        self::assertStringContainsString('nowo-ui-modal__header', $content);
        self::assertStringContainsString('nowo-ui-modal__content', $content);
        self::assertStringContainsString('nowo-ui-modal__actions', $content);
        self::assertStringContainsString('{% for child in form %}', $content);
        self::assertStringContainsString('{% if not child.rendered %}', $content);
    }

    #[Test]
    public function deleteConfirmComposesUiKitNativeDialog(): void
    {
        $path    = dirname(__DIR__, 3) . '/src/Resources/views/admin/_delete_confirm.html.twig';
        $content = file_get_contents($path);
        self::assertNotFalse($content);
        self::assertStringContainsString('@NowoUiKitBundle/partials/_confirm.html.twig', $content);
        self::assertStringContainsString('_csrf_action_form.html.twig', $content);
    }

    #[Test]
    public function listTemplatesDoNotUseWindowConfirm(): void
    {
        foreach (['admin/index.html.twig', 'admin/tags/index.html.twig', 'admin/comments/index.html.twig'] as $relativePath) {
            $path    = dirname(__DIR__, 3) . '/src/Resources/views/' . $relativePath;
            $content = file_get_contents($path);
            self::assertNotFalse($content, sprintf('Missing %s', $relativePath));
            self::assertStringNotContainsString('confirm_message', $content, $relativePath);
            self::assertStringContainsString('_delete_confirm.html.twig', $content, $relativePath);
        }
    }
}
