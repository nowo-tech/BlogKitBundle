<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Model;

use Nowo\BlogKitBundle\Model\BlameableUserTrait;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BlameableUserTraitTest extends TestCase
{
    #[Test]
    public function createdByAndUpdatedByAcceptBlogUsers(): void
    {
        $entity = new class {
            use BlameableUserTrait;
        };
        $user = new TestUser();

        $entity->setCreatedBy($user);
        $entity->setUpdatedBy($user);

        self::assertSame($user, $entity->getCreatedBy());
        self::assertSame($user, $entity->getUpdatedBy());
    }

    #[Test]
    public function createdByAndUpdatedByDiscardNonBlogUsers(): void
    {
        $entity = new class {
            use BlameableUserTrait;
        };

        $entity->setCreatedBy(new stdClass());
        $entity->setUpdatedBy(new stdClass());

        self::assertNull($entity->getCreatedBy());
        self::assertNull($entity->getUpdatedBy());
    }
}
