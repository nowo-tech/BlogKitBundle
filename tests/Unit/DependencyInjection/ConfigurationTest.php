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
        self::assertSame(['ROLE_EDITOR'], $config['security']['manage_roles']);
        self::assertSame(['ROLE_MODERATOR'], $config['security']['moderate_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['configure_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertSame('tailwind', $config['web_ui']['css_framework']);
        self::assertSame('bootstrap-icons', $config['web_ui']['icon_set']);
        self::assertSame(20, $config['web_ui']['page_size']);
        self::assertSame('#', $config['web_ui']['privacy_url']);
        self::assertSame('', $config['doctrine']['table_prefix']);
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
}
