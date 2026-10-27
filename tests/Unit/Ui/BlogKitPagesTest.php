<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Ui;

use Nowo\BlogKitBundle\Ui\BlogKitPages;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class BlogKitPagesTest extends TestCase
{
    private const string CANONICAL = 'layouts/canonical.html.twig';

    private const string LEGACY = 'layouts/legacy.html.twig';

    #[Test]
    public function labelsUseTheBundleDomain(): void
    {
        self::assertEquals(new Label('admin.tags.title', 'NowoBlogKitBundle', ['%n%' => 1]), BlogKitPages::label('admin.tags.title', ['%n%' => 1]));
    }

    #[Test]
    public function templateOverridesComeFromWebUiTemplates(): void
    {
        $pages = new BlogKitPages(templates: ['article_index' => 'admin/blog/articles.html.twig', 'tag_index' => '']);

        self::assertSame('admin/blog/articles.html.twig', $pages->template('article_index', '@NowoBlogKitBundle/admin/index.html.twig'));
        self::assertSame('@NowoBlogKitBundle/admin/tags/index.html.twig', $pages->template('tag_index', '@NowoBlogKitBundle/admin/tags/index.html.twig'));
        self::assertSame('@NowoBlogKitBundle/admin/form.html.twig', $pages->template('article_form', '@NowoBlogKitBundle/admin/form.html.twig'));
    }

    #[Test]
    public function nullLayoutsLeaveTheUiKitLayouts(): void
    {
        $pages = new BlogKitPages(twig: $this->twig());

        self::assertNull($pages->layout());
        self::assertNull($pages->publicLayout());
        self::assertSame(['x' => 1, 'nowo_blog_kit_legacy_layout' => null, 'nowo_blog_kit_legacy_blocks' => []], $pages->context(['x' => 1]));
        self::assertSame([
            'x'                                  => 1,
            'nowo_blog_kit_page_layout'          => null,
            'nowo_blog_kit_legacy_public_layout' => null,
            'nowo_blog_kit_legacy_blocks'        => [],
        ], $pages->publicContext(['x' => 1]));
    }

    #[Test]
    public function canonicalLayoutsAreUsedAsTheyAre(): void
    {
        $pages = new BlogKitPages(self::CANONICAL, self::CANONICAL, [], $this->twig());

        self::assertSame(self::CANONICAL, $pages->layout());
        self::assertSame(self::CANONICAL, $pages->publicLayout());
        self::assertNull($pages->context([])['nowo_blog_kit_legacy_layout']);
        self::assertSame(self::CANONICAL, $pages->publicContext([])['nowo_blog_kit_page_layout']);
    }

    #[Test]
    public function legacyLayoutsAreWrappedInTheBridges(): void
    {
        $pages = new BlogKitPages(self::LEGACY, self::LEGACY, [], $this->twig());

        $this->expectUserDeprecationMessageMatches('/The layout "layouts\/legacy\.html\.twig" does not render the UiKit canonical blocks/');

        self::assertSame(BlogKitPages::LEGACY_ADMIN_BRIDGE, $pages->layout());
        self::assertSame(BlogKitPages::LEGACY_PUBLIC_BRIDGE, $pages->publicLayout());
        self::assertSame(['nowo_blog_kit_legacy_layout' => self::LEGACY, 'nowo_blog_kit_legacy_blocks' => ['nowo_ui_flashes', 'nowo_ui_content']], $pages->context([]));
        self::assertSame([
            'nowo_blog_kit_page_layout'          => BlogKitPages::LEGACY_PUBLIC_BRIDGE,
            'nowo_blog_kit_legacy_public_layout' => self::LEGACY,
            'nowo_blog_kit_legacy_blocks'        => ['nowo_ui_flashes', 'nowo_ui_content'],
        ], $pages->publicContext([]));
    }

    #[Test]
    public function unknownLayoutsAndMissingTwigAreNotTreatedAsLegacy(): void
    {
        self::assertSame('missing.html.twig', new BlogKitPages('missing.html.twig', null, [], $this->twig())->layout());
        self::assertSame(self::LEGACY, new BlogKitPages(self::LEGACY)->layout());
        self::assertSame(['nowo_blog_kit_legacy_layout' => null, 'nowo_blog_kit_legacy_blocks' => []], new BlogKitPages(self::LEGACY)->context([]));
    }

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            self::CANONICAL => '{% block nowo_ui_page_header %}{% endblock %}{% block nowo_ui_content %}{% endblock %}',
            self::LEGACY    => '<title>{% block title %}{% endblock %}</title>{% block nowo_ui_flashes %}{% endblock %}{% block admin %}{% endblock %}{% block nowo_ui_content %}{% endblock %}',
        ]));
    }
}
