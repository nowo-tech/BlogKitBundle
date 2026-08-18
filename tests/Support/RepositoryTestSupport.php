<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;

/**
 * Lightweight doubles for service unit tests that previously used App\Tests helpers.
 */
final class RepositoryTestSupport
{
    public static function blogSettingsRepository(BlogSettings $settings): BlogSettingsRepository
    {
        return new class($settings) extends BlogSettingsRepository {
            public function __construct(private BlogSettings $singleton)
            {
            }

            public function getSingleton(): BlogSettings
            {
                return $this->singleton;
            }

            public function reset(): void
            {
            }
        };
    }
}
