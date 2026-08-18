<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AllowAllBlogKitResourceAccessCheckerTest extends TestCase
{
    #[Test]
    public function everyObjectActionIsGrantedWithoutListingRestriction(): void
    {
        $checker = new AllowAllBlogKitResourceAccessChecker();

        self::assertTrue($checker->canManageArticle(new BlogArticle()));
        self::assertTrue($checker->canManageTag(new BlogTag()));
        self::assertTrue($checker->canModerateComment(new BlogComment()));
        self::assertNull($checker->articleListingCreatedById());
    }
}
