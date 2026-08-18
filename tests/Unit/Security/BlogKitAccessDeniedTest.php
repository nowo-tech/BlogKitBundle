<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitAccessDenied;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BlogKitAccessDeniedTest extends TestCase
{
    #[Test]
    public function allowAllCheckerDoesNotThrow(): void
    {
        $denied = new BlogKitAccessDenied(new AllowAllBlogKitResourceAccessChecker());

        $denied->denyUnlessCanManageArticle(new BlogArticle());
        $denied->denyUnlessCanManageTag(new BlogTag());
        $denied->denyUnlessCanModerateComment(new BlogComment());

        self::assertInstanceOf(
            AllowAllBlogKitResourceAccessChecker::class,
            $denied->resourceAccess(),
        );
    }

    #[Test]
    public function denyUnlessCanManageArticleThrowsWhenCheckerRejects(): void
    {
        $denied = new BlogKitAccessDenied($this->rejectingChecker());

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('publication');

        $denied->denyUnlessCanManageArticle(new BlogArticle());
    }

    #[Test]
    public function denyUnlessCanManageTagThrowsWhenCheckerRejects(): void
    {
        $denied = new BlogKitAccessDenied($this->rejectingChecker());

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('tag');

        $denied->denyUnlessCanManageTag(new BlogTag());
    }

    #[Test]
    public function denyUnlessCanModerateCommentThrowsWhenCheckerRejects(): void
    {
        $denied = new BlogKitAccessDenied($this->rejectingChecker());

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('comment');

        $denied->denyUnlessCanModerateComment(new BlogComment());
    }

    private function rejectingChecker(): BlogKitResourceAccessCheckerInterface
    {
        $checker = $this->createMock(BlogKitResourceAccessCheckerInterface::class);
        $checker->method('canManageArticle')->willReturn(false);
        $checker->method('canManageTag')->willReturn(false);
        $checker->method('canModerateComment')->willReturn(false);

        return $checker;
    }
}
