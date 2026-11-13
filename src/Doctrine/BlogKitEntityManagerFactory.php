<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\BlogKitBundle\Entity\BlogArticle;

/**
 * Since 1.9.0: the entity manager of the blog entities when `doctrine.entity_manager` is not set: the manager that maps
 * them (your default manager in a standard host, unchanged behaviour). Doctrine managers are lazy services reset in
 * place, so the returned reference stays valid in a kept-alive kernel.
 *
 * @internal service factory
 */
final class BlogKitEntityManagerFactory
{
    public static function create(ManagerRegistry $registry): EntityManagerInterface
    {
        $manager = $registry->getManagerForClass(BlogArticle::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager maps ' . BlogArticle::class . ': register the NowoBlogKitBundle bundle (it maps its entities on your default manager) or set nowo_blog_kit.doctrine.entity_manager.');
        }

        return $manager;
    }
}
