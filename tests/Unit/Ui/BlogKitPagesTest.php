<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Ui;

use LogicException;
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
        self::assertNull(new BlogKitPages('', '', [], $this->twig())->layout(), 'an empty layout is no layout');
        self::assertSame(['x' => 1], $pages->context(['x' => 1]));
        self::assertSame(['x' => 1, 'nowo_blog_kit_page_layout' => null], $pages->publicContext(['x' => 1]));
    }

    #[Test]
    public function canonicalLayoutsAreUsedAsTheyAre(): void
    {
        $pages = new BlogKitPages(self::CANONICAL, self::CANONICAL, [], $this->twig());

        self::assertSame(self::CANONICAL, $pages->layout());
        self::assertSame(self::CANONICAL, $pages->publicLayout());
        self::assertSame([], $pages->context([]));
        self::assertSame(self::CANONICAL, $pages->publicContext([])['nowo_blog_kit_page_layout']);
    }

    /** Since 2.0 the 1.7 bridges are removed: a 1.x layout fails clearly instead of being wrapped. */
    #[Test]
    public function legacyLayoutsFailClearly(): void
    {
        $pages = new BlogKitPages(self::LEGACY, self::LEGACY, [], $this->twig());

        foreach ([
            ['nowo_blog_kit.web_ui.layout_template', '@NowoUiKit/layout/admin.html.twig', $pages->layout(...)],
            ['nowo_blog_kit.web_ui.public_layout_template', '@NowoUiKit/layout/guest.html.twig', static fn (): array => $pages->publicContext([])],
        ] as [$key, $uiKit, $resolve]) {
            try {
                $resolve();
                self::fail('A 1.x layout must fail: ' . $key);
            } catch (LogicException $exception) {
                self::assertSame('The layout "layouts/legacy.html.twig" (' . $key . ') does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...). The 1.x layout bridge (deprecated since 1.7) was removed in 2.0: render the canonical blocks (or extend ' . $uiKit . '), or set the option to null to use nowo_ui_kit.layouts; see UPGRADING.md "To 2.0.0" and TEMPLATES.md.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function unknownLayoutsAndMissingTwigAreLeftToTwig(): void
    {
        self::assertSame('missing.html.twig', new BlogKitPages('missing.html.twig', null, [], $this->twig())->layout());
        self::assertSame(self::LEGACY, new BlogKitPages(self::LEGACY)->layout());
        self::assertSame([], new BlogKitPages(self::LEGACY)->context([]));
    }

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            self::CANONICAL => '{% block nowo_ui_page_header %}{% endblock %}{% block nowo_ui_content %}{% endblock %}',
            self::LEGACY    => '<title>{% block title %}{% endblock %}</title>{% block nowo_ui_flashes %}{% endblock %}{% block admin %}{% endblock %}{% block nowo_ui_content %}{% endblock %}',
        ]));
    }
}
