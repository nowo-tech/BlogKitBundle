<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use LogicException;
use Nowo\BlogKitBundle\Tests\Integration\Kernel\LegacyBridgeKernel;
use Nowo\BlogKitBundle\Ui\BlogKitPages;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;
use function uniqid;

/**
 * The container injects the TwigBundle `twig` service into BlogKitPages, so a configured 1.x layout (no UiKit
 * canonical blocks) is detected. Since 2.0 the 1.7 bridge around such a layout (deprecated since 1.7) is removed: the
 * layout fails clearly, naming itself and the option, instead of rendering a page without its content.
 */
final class LayoutContractWiringTest extends TestCase
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
    public function aConfigured1xLayoutFailsClearly(): void
    {
        $pages = $this->pages('legacy.html.twig');

        foreach ([
            'web_ui.layout_template'        => $pages->layout(...),
            'web_ui.public_layout_template' => $pages->publicLayout(...),
        ] as $key => $resolve) {
            try {
                $resolve();
                self::fail('A 1.x layout must fail: ' . $key);
            } catch (LogicException $exception) {
                self::assertStringContainsString('The layout "legacy.html.twig" (nowo_blog_kit.' . $key . ') does not render the UiKit canonical blocks', $exception->getMessage());
                self::assertStringContainsString('The 1.x layout bridge (deprecated since 1.7) was removed in 2.0', $exception->getMessage());
            }
        }

        self::assertSame(['x' => 1], $pages->context(['x' => 1]), 'no bridge variables any more');
    }

    #[Test]
    public function aCanonicalLayoutIsUsedAsIs(): void
    {
        $pages = $this->pages('canonical.html.twig');

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame('canonical.html.twig', $pages->publicLayout());
        self::assertSame(['nowo_blog_kit_page_layout' => 'canonical.html.twig'], $pages->publicContext([]));
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
