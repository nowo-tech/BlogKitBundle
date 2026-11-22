<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Error;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitPortsPass;
use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Port\Ports;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Security\Internal\ObjectAccessCheckerInterface;
use Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\ImplementsAccess\LegacyOwnerChecker;
use Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\Unrelated\OtherMissingInterfaceUser;
use Nowo\BlogKitBundle\Tests\Support\Twin\OwnerResourcePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BlogKitPortsPassTest extends TestCase
{
    #[Test]
    public function acceptsTheInterfaceFactoriesAndUnknownClasses(): void
    {
        $container = $this->container();
        $container->register('twin', OwnerResourcePolicy::class);
        $container->register('factory', ObjectAccessCheckerInterface::class)->setFactory([Ports::class, 'resourceAccess']);
        $container->register('unknown', 'App\\Missing\\Policy');
        $container->setParameter('policy.class', OwnerResourcePolicy::class);
        $container->register('parameter', '%policy.class%');
        $container->setAlias('aliased', 'twin');
        $ports = [];
        foreach (['twin', 'factory', 'unknown', 'parameter', 'aliased', 'missing'] as $id) {
            $ports['key.' . $id] = [$id, BlogKitResourcePolicyInterface::class];
        }
        $container->setParameter(NowoBlogKitExtension::PORTS_PARAMETER, $ports);

        new BlogKitPortsPass()->process($container);
        new BlogKitPortsPass()->process(new ContainerBuilder());
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aServiceNotImplementingTheInterfaceFailsTheBuild(): void
    {
        $container = $this->container();
        $container->register('wrong', AllowAllBlogKitResourceAccessChecker::class);
        $container->setParameter(NowoBlogKitExtension::PORTS_PARAMETER, ['security.object_access.service' => ['wrong', BlogKitResourcePolicyInterface::class]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_blog_kit.security.object_access.service is "wrong" (' . AllowAllBlogKitResourceAccessChecker::class . '), which does not implement ' . BlogKitResourcePolicyInterface::class . ' (since 2.0 the only interface this key takes');
        new BlogKitPortsPass()->process($container);
    }

    /**
     * W1-1: a configured port whose class still implements a removed 1.x interface cannot be loaded; the build names
     * the removed interface and its replacement instead of failing later with "Interface not found".
     */
    #[Test]
    public function aServiceStillImplementingARemovedInterfaceFailsTheBuildWithTheReplacement(): void
    {
        $container = $this->container();
        $container->register('legacy', LegacyOwnerChecker::class);
        $container->setParameter(NowoBlogKitExtension::PORTS_PARAMETER, ['security.object_access.service' => ['legacy', BlogKitResourcePolicyInterface::class]]);

        try {
            new BlogKitPortsPass()->process($container);
            self::fail('The build must fail.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('nowo_blog_kit.security.object_access.service is "legacy" (' . LegacyOwnerChecker::class . '), which cannot be loaded: Interface "Nowo\\BlogKitBundle\\Security\\BlogKitResourceAccessCheckerInterface" not found', $exception->getMessage());
            self::assertStringContainsString('(deprecated since 1.9) was removed in 2.0', $exception->getMessage());
            self::assertStringContainsString('implement "' . BlogKitResourcePolicyInterface::class . '"', $exception->getMessage());
        }
    }

    /** Another loading problem of a configured port is left as it is. */
    #[Test]
    public function anUnrelatedLoadingErrorIsRethrown(): void
    {
        $container = $this->container();
        $container->register('other', OtherMissingInterfaceUser::class);
        $container->setParameter(NowoBlogKitExtension::PORTS_PARAMETER, ['security.object_access.service' => ['other', BlogKitResourcePolicyInterface::class]]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Interface "Nowo\\BlogKitBundle\\Security\\BlogKitResourceAccessCheckerInterfaceFactory" not found');
        new BlogKitPortsPass()->process($container);
    }

    private function container(): ContainerBuilder
    {
        return new ContainerBuilder();
    }
}
