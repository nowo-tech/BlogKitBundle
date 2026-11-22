<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection;

use Nowo\BlogKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function defaultConfigurationMatchesBundleDefaults(): void
    {
        $config = new Processor()->processConfiguration(new Configuration(), [[]]);

        self::assertNull($config['user_class']);
        self::assertSame('es', $config['default_locale']);
        self::assertSame(['es', 'en'], $config['locales']);
        self::assertSame('Team', $config['comments']['staff_name']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertSame(['manage' => ['ROLE_EDITOR'], 'moderate' => ['ROLE_MODERATOR'], 'configure' => ['ROLE_ADMIN']], $config['security']['roles']);
        foreach (['manage_roles', 'moderate_roles', 'configure_roles'] as $removed) {
            self::assertArrayNotHasKey($removed, $config['security'], 'removed in 2.0');
        }
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertSame('none', $config['security']['object_access']['strategy']);
        self::assertNull($config['security']['object_access']['service']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertNull($config['web_ui']['public_layout_template']);
        self::assertSame([], $config['web_ui']['templates']);
        foreach (['css_framework', 'icon_set', 'row_actions_display'] as $removed) {
            self::assertArrayNotHasKey($removed, $config['web_ui'], 'removed in 2.0');
        }
        self::assertNull($config['doctrine']['private_entity_manager']);
        self::assertNull($config['doctrine']['entity_manager']);
        self::assertSame(20, $config['web_ui']['page_size']);
        self::assertSame('#', $config['web_ui']['privacy_url']);
        self::assertSame('paginated', $config['listing']['mode']);
        self::assertSame('masonry', $config['listing']['masonry']['strategy']);
        self::assertSame(1, $config['listing']['masonry']['columns_mobile']);
        self::assertSame(2, $config['listing']['masonry']['columns_tablet']);
        self::assertSame(2, $config['listing']['masonry']['columns_desktop']);
        self::assertSame('', $config['doctrine']['table_prefix']);
        self::assertSame('fixed_window', $config['comments']['rate_limit']['strategy']);
        self::assertSame(5, $config['comments']['rate_limit']['limit']);
        self::assertSame(60, $config['comments']['rate_limit']['interval_seconds']);
        self::assertNull($config['comments']['rate_limit']['service']);
        self::assertSame('honeypot', $config['comments']['captcha']['strategy']);
        self::assertSame('', $config['comments']['captcha']['site_key']);
        self::assertSame(0.5, $config['comments']['captcha']['min_score']);
        self::assertSame('website', $config['comments']['captcha']['honeypot_field']);
        self::assertSame(5.0, $config['comments']['captcha']['timeout_seconds']);
        self::assertSame('allowlist', $config['html']['sanitize']['strategy']);
        self::assertNull($config['html']['sanitize']['service']);
    }

    #[Test]
    public function pageSizeRejectsZero(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'web_ui' => ['page_size' => 0],
        ]]);
    }

    #[Test]
    public function pageSizeRejectsValuesAboveMaximum(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'web_ui' => ['page_size' => 201],
        ]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function removedKeys(): iterable
    {
        yield 'security.manage_roles' => [['security' => ['manage_roles' => ['ROLE_WRITER']]], 'The option "nowo_blog_kit.security.manage_roles" was removed in 2.0 (deprecated since 1.7): use "security.roles.manage". See UPGRADING.md "To 2.0.0".'];
        yield 'security.moderate_roles' => [['security' => ['moderate_roles' => []]], 'The option "nowo_blog_kit.security.moderate_roles" was removed in 2.0 (deprecated since 1.7): use "security.roles.moderate".'];
        yield 'security.configure_roles' => [['security' => ['configure_roles' => null]], 'The option "nowo_blog_kit.security.configure_roles" was removed in 2.0 (deprecated since 1.7): use "security.roles.configure".'];
        yield 'web_ui.css_framework' => [['web_ui' => ['css_framework' => 'tailwind']], 'The option "nowo_blog_kit.web_ui.css_framework" was removed in 2.0 (deprecated since 1.7): set "nowo_ui_kit.css_framework". See UPGRADING.md "To 2.0.0".'];
        yield 'web_ui.icon_set' => [['web_ui' => ['icon_set' => null]], 'The option "nowo_blog_kit.web_ui.icon_set" was removed in 2.0 (deprecated since 1.7): set "nowo_ui_kit.icon_set".'];
        yield 'web_ui.row_actions_display' => [['web_ui' => ['row_actions_display' => 'inline']], 'The option "nowo_blog_kit.web_ui.row_actions_display" was removed in 2.0 (deprecated since 1.7): set "nowo_ui_kit.row_actions_display".'];
    }

    /**
     * Since 2.0 (owner answer Q-W4-1): the 1.7 keys are removed; a leftover key (any value, null included) fails the
     * build with what to set instead, never silently.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('removedKeys')]
    #[Test]
    public function aRemovedKeyFailsWithItsReplacement(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        new Processor()->processConfiguration(new Configuration(), [$config]);
    }

    #[Test]
    public function commentRateLimitStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'comments' => ['rate_limit' => ['strategy' => 'burst']],
        ]]);
    }

    #[Test]
    public function htmlSanitizeStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'html' => ['sanitize' => ['strategy' => 'bleach']],
        ]]);
    }

    #[Test]
    public function commentCaptchaStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'comments' => ['captcha' => ['strategy' => 'botdetect']],
        ]]);
    }

    #[Test]
    public function listingModeRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'listing' => ['mode' => 'masonry'],
        ]]);
    }

    #[Test]
    public function masonryStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'listing' => ['masonry' => ['strategy' => 'waterfall']],
        ]]);
    }

    #[Test]
    public function masonryColumnsRejectsValuesOutsideRange(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'listing' => ['masonry' => ['columns_desktop' => 4]],
        ]]);
    }

    #[Test]
    public function objectAccessStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new Configuration(), [[
            'security' => ['object_access' => ['strategy' => 'acl']],
        ]]);
    }

    #[Test]
    public function legacyDefaultLayoutsMapToTheUiKitLayouts(): void
    {
        $config = new Processor()->processConfiguration(new Configuration(), [[
            'web_ui' => [
                'layout_template'        => '@NowoBlogKitBundle/admin/layout.html.twig',
                'public_layout_template' => '@NowoBlogKitBundle/public/layout.html.twig',
                'templates'              => ['article_index' => 'admin/blog/articles.html.twig'],
            ],
        ]]);

        self::assertNull($config['web_ui']['layout_template']);
        self::assertNull($config['web_ui']['public_layout_template']);
        self::assertSame(['article_index' => 'admin/blog/articles.html.twig'], $config['web_ui']['templates']);
    }

    #[Test]
    public function hostLayoutsAreKept(): void
    {
        $config = new Processor()->processConfiguration(new Configuration(), [[
            'web_ui' => ['layout_template' => 'admin/layout.html.twig', 'public_layout_template' => 'base.html.twig'],
        ]]);

        self::assertSame('admin/layout.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('base.html.twig', $config['web_ui']['public_layout_template']);
    }
}
