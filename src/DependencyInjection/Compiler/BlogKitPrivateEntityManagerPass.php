<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\NowoBlogKitExtension;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;

/**
 * Since 1.12.0 (the private entity manager; since 2.0 the only mode without `doctrine.entity_manager`): resolves your manager the private one is created on — your
 * default entity manager (`doctrine.default_entity_manager`, so a default manager not named "default" works without
 * configuration) — then runs transaction-doctrine's {@see PrivateEntityManagerPass}, which checks that manager exists
 * and tags the bundle's PrivateEntityManagerMiddleware `doctrine.middleware` for its connection only.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0). Does nothing with a dedicated `doctrine.entity_manager`.
 */
final class BlogKitPrivateEntityManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoBlogKitExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
            return;
        }

        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $source  = is_string($default) && $default !== '' ? $default : 'default';
        $container->setParameter(NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, $source);

        new PrivateEntityManagerPass(
            NowoBlogKitExtension::PRIVATE_MIDDLEWARE_SERVICE,
            NowoBlogKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER,
            'nowo_blog_kit.doctrine.private_entity_manager (your default entity manager)',
        )->process($container);
    }
}
