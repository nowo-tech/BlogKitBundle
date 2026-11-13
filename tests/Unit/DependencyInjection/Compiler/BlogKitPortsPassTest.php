<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitPortsPass;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Port\Ports;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Tests\Support\Twin\OwnerResourcePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BlogKitPortsPassTest extends TestCase
{
    #[Test]
    public function acceptsTheLegacyInterfaceTheTwinAndUnknownClasses(): void
    {
        $container = $this->container();
        $container->register('legacy', AllowAllBlogKitResourceAccessChecker::class);
        $container->register('twin', OwnerResourcePolicy::class);
        $container->register('factory', BlogKitResourceAccessCheckerInterface::class)->setFactory([Ports::class, 'resourceAccess']);
        $container->register('unknown', 'App\\Missing\\Policy');
        $container->setParameter('policy.class', OwnerResourcePolicy::class);
        $container->register('parameter', '%policy.class%');
        $container->setAlias('aliased', 'twin');
        $ports = [];
        foreach (['legacy', 'twin', 'factory', 'unknown', 'parameter', 'aliased', 'missing'] as $id) {
            $ports['key.' . $id] = [$id, BlogKitResourceAccessCheckerInterface::class, BlogKitResourcePolicyInterface::class];
        }
        $container->setParameter(NowoBlogKitExtension::PORTS_PARAMETER, $ports);

        new BlogKitPortsPass()->process($container);
        new BlogKitPortsPass()->process(new ContainerBuilder());
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aServiceImplementingNeitherFailsTheBuild(): void
    {
        $container = $this->container();
        $container->register('wrong', stdClass::class);
        $container->setParameter(NowoBlogKitExtension::PORTS_PARAMETER, ['security.object_access.service' => ['wrong', BlogKitResourceAccessCheckerInterface::class, BlogKitResourcePolicyInterface::class]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_blog_kit.security.object_access.service is "wrong" (stdClass), which implements neither');
        new BlogKitPortsPass()->process($container);
    }

    private function container(): ContainerBuilder
    {
        return new ContainerBuilder();
    }
}
