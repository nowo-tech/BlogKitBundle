<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\User;

use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use Nowo\BlogKitBundle\User\BlogUserDirectoryInterface;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class BlogUsersTest extends TestCase
{
    #[Test]
    public function entityModeKeepsThe18Behaviour(): void
    {
        $user  = new TestUser()->setId(3);
        $users = new BlogUsers(BlogUsers::ENTITY, $this->storage($user));

        self::assertFalse($users->idMode());
        self::assertTrue($users->joinable());
        self::assertSame('IDENTITY(b.createdBy)', $users->identity('b.createdBy'));
        self::assertSame($user, $users->current());
        self::assertSame($user, $users->currentUser());
        self::assertNull($users->reference(new PlainHostUser(3)));
        self::assertSame('user@example.test', $users->label($user));
        self::assertSame('', $users->label(null));
    }

    #[Test]
    public function idModeReferencesAnyHostUserById(): void
    {
        $users = new BlogUsers(BlogUsers::ID, $this->storage(new PlainHostUser('alice')));

        self::assertTrue($users->idMode());
        self::assertFalse($users->joinable());
        self::assertSame('b.createdBy', $users->identity('b.createdBy'));
        self::assertEquals(new BlogUserRef('alice'), $users->current());
        self::assertNull($users->reference(new PlainHostUser('alice ')), 'an id that cannot be kept exactly is refused (fail closed)');
        self::assertSame('bob', $users->label(new BlogUserRef('bob')));
    }

    #[Test]
    public function labelsComeFromTheDirectory(): void
    {
        $directory = new class implements BlogUserDirectoryInterface {
            public function labelOf(string $userId): ?string
            {
                return ['alice' => 'Alice', 'empty' => ''][$userId] ?? null;
            }
        };
        $users = new BlogUsers(BlogUsers::ID, null, $directory);

        self::assertSame('Alice', $users->label(new BlogUserRef('alice')));
        self::assertSame('empty', $users->label(new BlogUserRef('empty')));
        self::assertSame('ghost', $users->label(new BlogUserRef('ghost')));
        self::assertNull($users->current(), 'no token storage, no user');
    }

    #[Test]
    public function aNonObjectUserIsNoUser(): void
    {
        self::assertNull(new BlogUsers(BlogUsers::ENTITY, new TokenStorage())->currentUser());
    }

    private function storage(UserInterface $user): TokenStorage
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $storage;
    }
}
