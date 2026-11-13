<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Port\Ports;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Since 1.9.0 (REQ-ARCH-001 rules 1 and 3): stops the container build when a configured host port service implements
 * neither the 1.x interface nor its twin. A service whose class the build cannot know (a factory, an unknown class)
 * is checked when it is built ({@see Ports}, fail closed).
 */
final class BlogKitPortsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoBlogKitExtension::PORTS_PARAMETER)) {
            return;
        }

        /** @var array<string, array{0: string, 1: class-string, 2: class-string}> $ports */
        $ports = $container->getParameter(NowoBlogKitExtension::PORTS_PARAMETER);
        foreach ($ports as $key => [$serviceId, $legacy, $twin]) {
            if (!$container->has($serviceId)) {
                continue;
            }

            $definition = $container->findDefinition($serviceId);
            $class      = $definition->getFactory() === null ? $container->getParameterBag()->resolveValue($definition->getClass() ?? $serviceId) : null;
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            if (!is_a($class, $legacy, true) && !is_a($class, $twin, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_blog_kit.%s is "%s" (%s), which implements neither %s nor the deprecated %s.', $key, $serviceId, $class, $twin, $legacy));
            }
        }
    }
}
