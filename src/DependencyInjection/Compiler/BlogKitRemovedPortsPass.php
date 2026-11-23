<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\BlogKitBundle\Port\Ports;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;
use function str_contains;

/**
 * Since 2.0 (REQ-ARCH-001 rules 1 and 3, lesson W1-1): refuses, when the container is built, every trace of the three
 * host ports removed in 2.0 (deprecated since 1.9, see {@see Ports::REMOVED}):
 *
 * - a service definition whose loading errors name a removed interface. A class registered by resource autodiscovery
 *   that still implements it cannot be loaded, so Symfony keeps the definition with only an error
 *   (`Class "…\BlogKitResourceAccessCheckerInterface" not found while loading "App\…"`) and never reads its
 *   `#[AsAlias]` / `#[AsDecorator]` attributes; unused, the definition would later be removed without a word. For the
 *   object access checker that would WIDEN access to the blog administration (a host decorator that restricted it
 *   would silently stop running). Only the exact class name, quoted as Symfony quotes it, matches: an unrelated
 *   missing class keeps its lazy error;
 * - a service or an alias registered under a removed id (`#[AsAlias]` of it on a loadable class, a `services.yaml`
 *   alias): 2.0 never reads those ids, so such a service is not silently ignored either;
 * - a decorator of a removed id (also with `onInvalid: IGNORE`, which Symfony would drop without a word). In 1.x the
 *   object access checker was aliased as `Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface`.
 *
 * Registered twice before optimization: at priority 1000, before any other pass looks at the definitions (loading
 * errors), and at priority 99, after Symfony has turned `#[AsDecorator]` attributes into decorated services. Each
 * message names the service, the removed interface, the version that deprecated it and the replacement.
 *
 * @internal registered by the bundle
 */
final class BlogKitRemovedPortsPass implements CompilerPassInterface
{
    /**
     * @throws LogicException on the first trace of a removed port
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (Ports::REMOVED as $removed => $replacement) {
            if ($container->hasAlias($removed) || $container->hasDefinition($removed)) {
                $target = $container->hasAlias($removed) ? sprintf(' (an alias of "%s")', (string) $container->getAlias($removed)) : '';

                throw new LogicException(sprintf('The service id "%s"%s is no longer read by NowoBlogKitBundle. %s', $removed, $target, self::hint($removed, $replacement)));
            }
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            foreach (Ports::REMOVED as $removed => $replacement) {
                foreach ($definition->getErrors() as $error) {
                    if (self::names((string) $error, $removed)) {
                        throw new LogicException(sprintf('The service "%s" cannot be loaded: %s %s', $id, (string) $error, self::hint($removed, $replacement)));
                    }
                }

                if (($definition->getDecoratedService()[0] ?? null) === $removed) {
                    throw new LogicException(sprintf('The service "%s" decorates "%s", which no longer exists. %s', $id, $removed, self::hint($removed, $replacement)));
                }
            }
        }
    }

    /**
     * Whether a loading error names exactly `$removed` (quoted as PHP and Symfony quote class names), so that
     * `…\FooInterface` never matches `…\FooPolicyInterface`.
     */
    public static function names(string $message, string $removed): bool
    {
        return str_contains($message, '"' . $removed . '"');
    }

    /**
     * What replaces a removed port, for the build errors.
     */
    public static function hint(string $removed, string $replacement): string
    {
        return sprintf('"%s" (deprecated since 1.9) was removed in 2.0, so a class that still implements it no longer loads: implement "%s" and configure it with the same nowo_blog_kit key (security.object_access.service, comments.rate_limit.service or comments.captcha.service with strategy: service); see UPGRADING.md "To 1.9.0" and "To 2.0.0".', $removed, $replacement);
    }
}
