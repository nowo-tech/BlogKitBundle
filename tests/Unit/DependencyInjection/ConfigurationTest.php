<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection;

use Nowo\BlogKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function defaultConfigurationMatchesBundleDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertNull($config['user_class']);
        self::assertSame('es', $config['default_locale']);
        self::assertSame(['es', 'en'], $config['locales']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertSame(['ROLE_EDITOR'], $config['security']['manage_roles']);
        self::assertSame(['ROLE_MODERATOR'], $config['security']['moderate_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['configure_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertSame('none', $config['security']['object_access']['strategy']);
        self::assertNull($config['security']['object_access']['service']);
        self::assertSame('bootstrap5', $config['web_ui']['css_framework']);
        self::assertSame('bootstrap-icons', $config['web_ui']['icon_set']);
        self::assertSame('icon', $config['web_ui']['row_actions_display']);
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
        self::assertSame('none', $config['html']['sanitize']['strategy']);
        self::assertNull($config['html']['sanitize']['service']);
    }

    #[Test]
    public function pageSizeRejectsZero(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['page_size' => 0],
        ]]);
    }

    #[Test]
    public function pageSizeRejectsValuesAboveMaximum(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['page_size' => 201],
        ]]);
    }

    #[Test]
    public function cssFrameworkRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['css_framework' => 'bulma'],
        ]]);
    }

    #[Test]
    public function iconSetRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['icon_set' => 'fontawesome'],
        ]]);
    }

    #[Test]
    public function rowActionsDisplayRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['row_actions_display' => 'hidden'],
        ]]);
    }

    #[Test]
    public function commentRateLimitStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'comments' => ['rate_limit' => ['strategy' => 'burst']],
        ]]);
    }

    #[Test]
    public function htmlSanitizeStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'html' => ['sanitize' => ['strategy' => 'bleach']],
        ]]);
    }

    #[Test]
    public function commentCaptchaStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'comments' => ['captcha' => ['strategy' => 'botdetect']],
        ]]);
    }

    #[Test]
    public function listingModeRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'listing' => ['mode' => 'masonry'],
        ]]);
    }

    #[Test]
    public function masonryStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'listing' => ['masonry' => ['strategy' => 'waterfall']],
        ]]);
    }

    #[Test]
    public function masonryColumnsRejectsValuesOutsideRange(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'listing' => ['masonry' => ['columns_desktop' => 4]],
        ]]);
    }

    #[Test]
    public function objectAccessStrategyRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'security' => ['object_access' => ['strategy' => 'acl']],
        ]]);
    }
}
