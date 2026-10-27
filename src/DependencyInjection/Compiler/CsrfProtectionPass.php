<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when form CSRF protection is unavailable (REQ-SEC-005).
 *
 * Every mutation of the bundle — admin article/tag/settings forms, delete and comment moderation actions
 * (FormKit CSRF-only forms) and the public comment POST — relies on Symfony's form CSRF extension. A host that
 * disables `framework.form.csrf_protection` (or has no CSRF token manager) would silently turn those checks off,
 * so the container refuses to compile instead.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /** Parameter set by NowoBlogKitExtension; its presence means the bundle is configured. */
    public const string BUNDLE_PARAMETER = 'nowo_blog_kit.web_ui.templates';

    /**
     * Throws when the bundle is loaded and form CSRF protection or the CSRF token manager is unavailable.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::BUNDLE_PARAMETER)) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new LogicException('NowoBlogKitBundle: the admin UI and the public comment form require CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf).');
        }
    }
}
