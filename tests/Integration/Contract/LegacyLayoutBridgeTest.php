<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Contract;

use Nowo\BlogKitBundle\Tests\Support\PageFixtures;
use Nowo\BlogKitBundle\Ui\BlogKitPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function substr_count;

/**
 * Deprecated 1.x layouts (no UiKit canonical blocks) keep working through the bridges: the page renders inside the
 * host blocks (`admin` / `body`), once, with the title, assets and flashes mapped, and the canonical blocks the 1.x
 * layout renders itself are not printed twice.
 */
final class LegacyLayoutBridgeTest extends TestCase
{
    public const array LAYOUTS = [
        // The documented 1.x admin bridge: renders flashes, admin and nowo_ui_content itself
        'legacy/admin.html.twig' => '<header data-host="admin"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}<link data-host-css>{% endblock %}</header>'
            . '<div data-host-flashes>{% block nowo_ui_flashes %}{% endblock %}</div><div data-host-admin>{% block admin %}{% endblock %}</div>'
            . '<div data-host-content>{% block nowo_ui_content %}{% endblock %}</div>{% block javascripts %}<script data-host-js></script>{% endblock %}',
        // A plain 1.x public layout (base.html.twig): body + meta_description
        'legacy/public.html.twig' => '<header data-host="public"><title>{% block title %}{% endblock %}</title><meta name="description" content="{% block meta_description %}{% endblock %}">'
            . '{% block stylesheets %}{% endblock %}</header><main data-host-body>{% block body %}{% endblock %}</main>{% block javascripts %}{% endblock %}',
    ];

    #[Test]
    public function aLegacyAdminLayoutRendersThePageOnceInsideItsBlocks(): void
    {
        $twig    = $this->twig();
        $factory = PageFixtures::factory(null, new BlogKitPages('legacy/admin.html.twig', null, [], $twig));

        $this->expectUserDeprecationMessageMatches('/legacy\/admin\.html\.twig/');
        $html = $twig->render('@NowoBlogKitBundle/admin/index.html.twig', $factory->context(PageFixtures::articleIndex(false, $factory)));

        self::assertSame(1, substr_count($html, 'data-host="admin"'));
        self::assertSame(1, substr_count($html, 'data-testid="blog-kit-articles"'), 'content once (host nowo_ui_content block)');
        self::assertMatchesRegularExpression('/<div data-host-content>\s*<section class="nowo-ui-list-page"/', $html);
        self::assertMatchesRegularExpression('/<title>Articles<\/title>/', $html);
        self::assertStringContainsString('data-host-css', $html);
        self::assertStringContainsString('/assets/css/nowo-ui.css', $html);
        self::assertStringContainsString('/assets/blog.css', $html);
        self::assertStringContainsString('data-host-js', $html);
        self::assertStringContainsString('data-nowo-ui-contract-partial="page_header"', $html, 'page header in the admin block');
        self::assertSame(1, substr_count($html, 'data-nowo-ui-region="flashes"') + substr_count($html, 'nowo-ui-flashes'), 'flashes once (host nowo_ui_flashes block)');
    }

    #[Test]
    public function aLegacyPublicLayoutRendersThePageInItsBody(): void
    {
        $twig    = $this->twig();
        $factory = PageFixtures::factory(null, new BlogKitPages(null, 'legacy/public.html.twig', [], $twig));

        $this->expectUserDeprecationMessageMatches('/legacy\/public\.html\.twig/');
        $html = $twig->render('@NowoBlogKitBundle/public/index.html.twig', $factory->publicContext(PageFixtures::publicIndex(false, $factory)));

        self::assertSame(1, substr_count($html, 'data-host="public"'));
        self::assertMatchesRegularExpression('/<main data-host-body>.*data-testid="blog-kit-index"/s', $html);
        self::assertSame(1, substr_count($html, 'data-testid="blog-kit-index"'));
        self::assertStringContainsString('<title>Blog</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Articles and notes.">', $html);
        self::assertSame(1, substr_count($html, 'name="description"'), 'meta description once (host block)');
        self::assertStringContainsString('/assets/blog-kit.js', $html);
        self::assertStringContainsString('data-nowo-ui-contract-partial="page_header"', $html);
    }

    private function twig(): Environment
    {
        $case = new class('legacy') extends PageContractTestCase {
            use BlogKitContractEnvironment;

            public static function pageFixtures(): iterable
            {
                return [];
            }

            public function environment(): Environment
            {
                return $this->createTwig();
            }

            protected static function inlineTemplates(): array
            {
                return LegacyLayoutBridgeTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
