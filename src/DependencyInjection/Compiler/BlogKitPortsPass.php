<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Port\Ports;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Since 1.9.0 (REQ-ARCH-001 rules 1 and 3): stops the container build when a configured host port service does not
 * implement the interface its key takes. Since 2.0 that is only the 1.9 interface (the 1.x ones were removed): a
 * class that still implements a removed interface cannot even be loaded, and the build says which one to implement.
 * A service whose class the build cannot know (a factory, an unknown class) is checked when it is built
 * ({@see Ports}, fail closed).
 */
final class BlogKitPortsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoBlogKitExtension::PORTS_PARAMETER)) {
            return;
        }

        /** @var array<string, array{0: string, 1: class-string}> $ports */
        $ports = $container->getParameter(NowoBlogKitExtension::PORTS_PARAMETER);
        foreach ($ports as $key => [$serviceId, $interface]) {
            if (!$container->has($serviceId)) {
                continue;
            }

            $definition = $container->findDefinition($serviceId);
            $class      = $definition->getFactory() === null ? $container->getParameterBag()->resolveValue($definition->getClass() ?? $serviceId) : null;
            if (!is_string($class)) {
                continue;
            }

            try {
                $exists = class_exists($class);
            } catch (Throwable $throwable) {
                foreach (Ports::REMOVED as $removed => $replacement) {
                    if (BlogKitRemovedPortsPass::names($throwable->getMessage(), $removed)) {
                        throw new InvalidConfigurationException(sprintf('nowo_blog_kit.%s is "%s" (%s), which cannot be loaded: %s %s', $key, $serviceId, $class, $throwable->getMessage(), BlogKitRemovedPortsPass::hint($removed, $replacement)), 0, $throwable);
                    }
                }

                throw $throwable;
            }

            if ($exists && !is_a($class, $interface, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_blog_kit.%s is "%s" (%s), which does not implement %s (since 2.0 the only interface this key takes; see UPGRADING.md "To 2.0.0").', $key, $serviceId, $class, $interface));
            }
        }
    }
}
