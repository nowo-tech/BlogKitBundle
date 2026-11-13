<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\BlogKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_keys;
use function array_unshift;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 6, opt-in `doctrine.entity_manager`): keeps the blog entities on the bundle's own
 * manager only (LegalKit 1.13.1 / DashboardMenu 2.21.1 pattern).
 *
 * The bundle class maps the entities on that manager. DoctrineBundle's `auto_mapping` would map them on the default
 * manager too, and `getManagerForClass()` (the repositories) would then answer the default manager. This pass removes
 * the bundle namespace from the mapping driver chain of every other manager and registers an
 * {@see ExcludedEntityNamespaceDriver} for it there (first in the chain), so a default driver of that chain cannot
 * claim the entities either (DoctrineBundle 3.4 `auto_discover_entities`). It fails the build when the configured
 * manager does not exist, is the default manager, does not map the blog entities, or also maps other classes (the
 * bundle owns that manager: it clears it between requests).
 */
final readonly class BlogKitEntityManagerPass implements CompilerPassInterface
{
    public const string ENTITY_NAMESPACE = 'Nowo\\BlogKitBundle\\Entity';

    /**
     * @param bool $checkOnly only check that the manager exists (registered before the mapping passes, which would
     *                        otherwise fail on its missing driver chain with a less helpful message)
     */
    public function __construct(
        private bool $checkOnly = false,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $name = $container->hasParameter(NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoBlogKitExtension::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_blog_kit.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }

        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        if ($name === $default) {
            throw new InvalidConfigurationException(sprintf('nowo_blog_kit.doctrine.entity_manager is "%s", your default Doctrine entity manager. The option names a manager the bundle owns (it clears it between requests): add a separate manager for the blog entities under doctrine.orm.entity_managers, or remove the option to keep the blog on your default manager.', $name));
        }

        if ($this->checkOnly) {
            return;
        }

        $foreign = [];
        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $namespace = $call[0] === 'addDriver' ? ($call[1][1] ?? null) : null;
                $isBundle  = is_string($namespace) && str_starts_with($namespace, self::ENTITY_NAMESPACE);
                if ($isBundle && $manager !== $name) {
                    continue;
                }

                if ($manager === $name && !$isBundle && in_array($call[0], ['addDriver', 'setDefaultDriver'], true)) {
                    $foreign[] = is_string($namespace) ? $namespace : 'a default driver (every class)';
                }

                $maps    = $maps || $isBundle;
                $calls[] = $call;
            }

            if ($manager !== $name) {
                // First in the chain: a chain asks its namespace drivers in order, then its default driver.
                array_unshift($calls, ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), self::ENTITY_NAMESPACE]]);
            }

            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_blog_kit.doctrine.entity_manager) does not map %s.', $name, self::ENTITY_NAMESPACE));
            }
        }

        if ($foreign !== []) {
            throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_blog_kit.doctrine.entity_manager) also maps %s. The bundle owns that manager (it clears it between requests): give it a manager that maps only the blog entities (auto_mapping: false, no other mappings).', $name, implode(', ', $foreign)));
        }
    }
}
