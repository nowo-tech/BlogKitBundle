<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Contract;

use Nowo\BlogKitBundle\Security\AllowAllBlogKitAccessChecker;
use Nowo\BlogKitBundle\Tests\Support\PageFixtures;
use Nowo\BlogKitBundle\Twig\BlogKitExtension;
use Nowo\FormKitBundle\Twig\LocaleTabsTwigExtension;
use Twig\Environment;

use function dirname;

/**
 * Twig environment of the UiKit page contract test for the bundle: bundle and FormKit views and catalogues, the
 * resources collection form theme, the bundle Twig extension, strict missing-translation rule.
 *
 * @phpstan-require-extends \Nowo\UiKitBundle\Test\PageContractTestCase
 */
trait BlogKitContractEnvironment
{
    protected static function templatePaths(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            'NowoBlogKitBundle' => $root . '/src/Resources/views',
            'NowoFormKitBundle' => $root . '/vendor/nowo-tech/form-kit-bundle/src/Resources/views',
        ];
    }

    protected static function translationDirs(): array
    {
        $root = dirname(__DIR__, 3);

        return [$root . '/src/Resources/translations', $root . '/vendor/nowo-tech/form-kit-bundle/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function formThemes(): array
    {
        return ['form_div_layout.html.twig', '@NowoBlogKitBundle/admin/form_theme.html.twig'];
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addExtension(new BlogKitExtension(null, null, null, PageFixtures::LOCALE, [PageFixtures::LOCALE, 'es'], '/privacy', new AllowAllBlogKitAccessChecker()));
        $twig->addExtension(new LocaleTabsTwigExtension());
    }
}
