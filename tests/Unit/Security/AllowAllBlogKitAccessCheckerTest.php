<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security;

use Nowo\BlogKitBundle\Security\AllowAllBlogKitAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AllowAllBlogKitAccessCheckerTest extends TestCase
{
    #[Test]
    public function everyCapabilityIsGranted(): void
    {
        $checker = new AllowAllBlogKitAccessChecker();

        self::assertTrue($checker->canManage());
        self::assertTrue($checker->canModerate());
        self::assertTrue($checker->canConfigure());
    }
}
