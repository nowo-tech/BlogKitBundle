<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Nowo\BlogKitBundle\Tests\Integration\Kernel\LegacyBridgeKernel;
use Nowo\BlogKitBundle\Ui\BlogKitPages;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;
use function uniqid;

/**
 * The container injects the TwigBundle `twig` service into BlogKitPages, so a configured 1.x layout (no UiKit
 * canonical blocks) is detected and wrapped in the deprecated bridge. Up to 1.8.0 the argument used
 * `#[Autowire('@?twig')]`, which is not an optional reference (the id became "?twig") and compiled to null: the bridge
 * was never taken.
 */
final class LegacyLayoutBridgeWiringTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-blog-kit-legacy-bridge-' . uniqid();
        $fs        = new Filesystem();
        $fs->dumpFile($this->dir . '/legacy.html.twig', '<html><body>{% block admin %}{% endblock %}{% block body %}{% endblock %}</body></html>');
        $fs->dumpFile($this->dir . '/canonical.html.twig', '<html><body>{% block nowo_ui_page_header %}{% endblock %}{% block nowo_ui_content %}{% endblock %}</body></html>');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->dir, sys_get_temp_dir() . '/nowo-blog-kit-legacy-bridge']);
    }

    #[Test]
    public function aConfigured1xLayoutIsWrappedInTheLegacyBridges(): void
    {
        $pages = $this->pages('legacy.html.twig');

        $this->expectUserDeprecationMessageMatches('/legacy\.html\.twig/');
        self::assertSame(BlogKitPages::LEGACY_ADMIN_BRIDGE, $pages->layout());
        self::assertSame(BlogKitPages::LEGACY_PUBLIC_BRIDGE, $pages->publicLayout());
        self::assertSame('legacy.html.twig', $pages->context([])['nowo_blog_kit_legacy_layout']);
    }

    #[Test]
    public function aCanonicalLayoutIsUsedAsIs(): void
    {
        $pages = $this->pages('canonical.html.twig');

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame('canonical.html.twig', $pages->publicLayout());
    }

    private function pages(string $layout): BlogKitPages
    {
        $kernel = new LegacyBridgeKernel($this->dir, $layout);
        $kernel->boot();
        $pages = $kernel->getContainer()->get(BlogKitPages::class);
        self::assertInstanceOf(BlogKitPages::class, $pages);

        return $pages;
    }
}
