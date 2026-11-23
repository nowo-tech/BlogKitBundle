<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Contract;

use LogicException;
use Nowo\BlogKitBundle\Tests\Support\PageFixtures;
use Nowo\BlogKitBundle\Ui\BlogKitPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Since 2.0 the 1.7 bridges around a 1.x layout (no UiKit canonical blocks; deprecated since 1.7) are removed: an admin
 * or public page with such a layout fails before it renders, with a message naming the layout and the option, instead
 * of rendering a page whose content never appears. The bridge templates are gone.
 */
final class LegacyLayoutRemovedTest extends TestCase
{
    public const array LAYOUTS = [
        'legacy/admin.html.twig'  => '<header data-host="admin"></header><div>{% block admin %}{% endblock %}</div>',
        'legacy/public.html.twig' => '<main>{% block body %}{% endblock %}</main>',
    ];

    #[Test]
    public function aLegacyAdminLayoutFailsBeforeThePageRenders(): void
    {
        $twig    = $this->twig();
        $factory = PageFixtures::factory(null, new BlogKitPages('legacy/admin.html.twig', null, [], $twig));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The layout "legacy/admin.html.twig" (nowo_blog_kit.web_ui.layout_template) does not render the UiKit canonical blocks');
        $factory->context(PageFixtures::articleIndex(false, $factory));
    }

    #[Test]
    public function aLegacyPublicLayoutFailsBeforeThePageRenders(): void
    {
        $twig    = $this->twig();
        $factory = PageFixtures::factory(null, new BlogKitPages(null, 'legacy/public.html.twig', [], $twig));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The layout "legacy/public.html.twig" (nowo_blog_kit.web_ui.public_layout_template) does not render the UiKit canonical blocks');
        $factory->publicContext(PageFixtures::publicIndex(false, $factory));
    }

    #[Test]
    public function theBridgeTemplatesAreGone(): void
    {
        $twig = $this->twig();

        self::assertFalse($twig->getLoader()->exists('@NowoBlogKitBundle/admin/_legacy_layout.html.twig'));
        self::assertFalse($twig->getLoader()->exists('@NowoBlogKitBundle/public/_legacy_layout.html.twig'));
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
                return LegacyLayoutRemovedTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
