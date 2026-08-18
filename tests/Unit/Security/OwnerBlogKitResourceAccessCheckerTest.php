<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\OwnerBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class OwnerBlogKitResourceAccessCheckerTest extends TestCase
{
    #[Test]
    public function configureRoleBypassesOwnershipAndListingFilter(): void
    {
        $article = $this->articleOwnedBy((new TestUser())->setId(1));
        $checker = $this->createOwnerChecker(configure: true, user: (new TestUser())->setId(99));

        self::assertTrue($checker->canManageArticle($article));
        self::assertNull($checker->articleListingCreatedById());
    }

    #[Test]
    public function editorCanManageOwnAndUnownedArticlesOnly(): void
    {
        $editor  = (new TestUser())->setId(7)->setEmail('editor@example.test');
        $other   = (new TestUser())->setId(8)->setEmail('other@example.test');
        $own     = $this->articleOwnedBy($editor);
        $foreign = $this->articleOwnedBy($other);
        $legacy  = new BlogArticle();

        $checker = $this->createOwnerChecker(configure: false, user: $editor);

        self::assertTrue($checker->canManageArticle($own));
        self::assertTrue($checker->canManageArticle($legacy));
        self::assertFalse($checker->canManageArticle($foreign));
        self::assertSame('7', $checker->articleListingCreatedById());
        self::assertTrue($checker->canManageTag(new BlogTag()));
        self::assertTrue($checker->canModerateComment(new BlogComment()));
    }

    #[Test]
    public function missingTokenStorageIsAnonymous(): void
    {
        $article = $this->articleOwnedBy((new TestUser())->setId(1));
        $checker = new OwnerBlogKitResourceAccessChecker($this->accessChecker(false));

        self::assertFalse($checker->canManageArticle($article));
        self::assertSame('', $checker->articleListingCreatedById());
    }

    #[Test]
    public function missingUserYieldsEmptyListingAndDeniesOwnedArticles(): void
    {
        $article = $this->articleOwnedBy((new TestUser())->setId(1));
        $checker = new OwnerBlogKitResourceAccessChecker(
            $this->accessChecker(false),
            $this->tokenStorage(null),
        );

        self::assertFalse($checker->canManageArticle($article));
        self::assertSame('', $checker->articleListingCreatedById());
    }

    #[Test]
    public function tokenWithoutUserIsTreatedAsAnonymous(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $checker = new OwnerBlogKitResourceAccessChecker($this->accessChecker(false), $storage);
        $article = $this->articleOwnedBy((new TestUser())->setId(1));

        self::assertFalse($checker->canManageArticle($article));
        self::assertSame('', $checker->articleListingCreatedById());
    }

    #[Test]
    public function identifierFallbackMatchesUsersWithoutIds(): void
    {
        $owner   = (new TestUser())->setId(null)->setEmail('owner@example.test');
        $same    = (new TestUser())->setId(null)->setEmail('owner@example.test');
        $article = $this->articleOwnedBy($owner);

        $checker = $this->createOwnerChecker(configure: false, user: $same);

        self::assertTrue($checker->canManageArticle($article));
        self::assertSame('', $checker->articleListingCreatedById());
    }

    private function createOwnerChecker(bool $configure, TestUser $user): OwnerBlogKitResourceAccessChecker
    {
        return new OwnerBlogKitResourceAccessChecker(
            $this->accessChecker($configure),
            $this->tokenStorage($user),
        );
    }

    private function accessChecker(bool $configure): BlogKitAccessCheckerInterface
    {
        $checker = $this->createMock(BlogKitAccessCheckerInterface::class);
        $checker->method('canConfigure')->willReturn($configure);

        return $checker;
    }

    private function tokenStorage(?UserInterface $user): TokenStorageInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn(!$user instanceof UserInterface ? null : $token);

        return $storage;
    }

    private function articleOwnedBy(TestUser $user): BlogArticle
    {
        $article = new BlogArticle();
        $article->setCreatedBy($user);

        return $article;
    }
}
