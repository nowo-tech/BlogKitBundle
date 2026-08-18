<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security;

use Nowo\BlogKitBundle\Security\ConfigurableBlogKitAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ConfigurableBlogKitAccessCheckerTest extends TestCase
{
    #[Test]
    public function emptyRoleListsAllowEveryCapability(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::never())->method('isGranted');

        $checker = new ConfigurableBlogKitAccessChecker($authorizationChecker, [], [], []);

        self::assertTrue($checker->canManage());
        self::assertTrue($checker->canModerate());
        self::assertTrue($checker->canConfigure());
    }

    #[Test]
    public function grantedRolesEnableTheMatchingCapability(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $attribute === 'ROLE_MODERATOR' || $attribute === 'ROLE_ADMIN',
        );

        $checker = new ConfigurableBlogKitAccessChecker(
            $authorizationChecker,
            ['ROLE_EDITOR'],
            ['ROLE_MODERATOR'],
            ['ROLE_ADMIN'],
        );

        self::assertFalse($checker->canManage());
        self::assertTrue($checker->canModerate());
        self::assertTrue($checker->canConfigure());
    }

    #[Test]
    public function canModerateFallsBackToManagePermission(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $attribute === 'ROLE_EDITOR',
        );

        $checker = new ConfigurableBlogKitAccessChecker(
            $authorizationChecker,
            ['ROLE_EDITOR'],
            ['ROLE_MODERATOR'],
            ['ROLE_ADMIN'],
        );

        self::assertTrue($checker->canManage());
        self::assertTrue($checker->canModerate());
        self::assertFalse($checker->canConfigure());
    }
}
