<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\EventSubscriber\BlogKitConflictSubscriber;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function is_int;

/**
 * Since 1.10.0 (REQ-RUNTIME-003 rule 6): wires the conflict answer of {@see BlogKitConflictSubscriber} once every extension is
 * loaded, so one setting drives the whole application.
 *
 * - `nowo_blog_kit.conflict_response.status` left out (null): with NowoTransactionDoctrineBundle registered, its policy
 *   (`nowo_transaction_doctrine.conflict_response.status` and `retry_after`) and, when its `conflict_response` is
 *   enabled, its responder (template, translations, JSON error key and path prefixes, the formatters of the
 *   application); without it, 409 + `Retry-After: 1` and the plain answer (English texts).
 * - `status: 409 | 503`: BlogKit's own policy with that status (`Retry-After: 1`); the bundle's template and
 *   translations are still used when it is registered.
 */
final class ConflictResponsePass implements CompilerPassInterface
{
    /** Parameter set by the extension: `conflict_response` (status, error_page). */
    public const string PARAMETER = 'nowo_blog_kit.conflict_response';

    /** Services of nowo-tech/transaction-doctrine's Symfony bundle. */
    public const string SHARED_POLICY    = 'nowo_transaction_doctrine.conflict_response_policy';
    public const string SHARED_RESPONDER = 'nowo_transaction_doctrine.conflict_responder';

    /** BlogKit's own policy, when `status` is set or the bundle is not registered. */
    public const string OWN_POLICY = 'nowo_blog_kit.conflict_response_policy';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(BlogKitConflictSubscriber::class)) {
            return;
        }
        /** @var array{status: int|null, error_page: bool} $config */
        $config = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : ['status' => null, 'error_page' => false];
        $status = $config['status'];
        $shared = $container->has(self::SHARED_POLICY);

        if (is_int($status) || !$shared) {
            $container->setDefinition(self::OWN_POLICY, new Definition(ConflictResponsePolicy::class, [$status ?? ConflictResponsePolicy::STATUS_CONFLICT]));
            $policy = new Reference(self::OWN_POLICY);
        } else {
            $policy = new Reference(self::SHARED_POLICY);
        }

        if (!is_int($status) && $container->has(self::SHARED_RESPONDER)) {
            $responder = new Reference(self::SHARED_RESPONDER);
        } else {
            // The bundle's template and translations exist only when it is registered; else the plain English answer.
            $responder = new Definition(ConcurrencyConflictResponder::class, [
                $policy,
                [],
                $shared ? new TaggedIteratorArgument(ConflictResponseFormatterInterface::TAG) : [],
                $shared ? new Reference('twig', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
                $shared ? new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
        }

        $container->getDefinition(BlogKitConflictSubscriber::class)
            ->setArgument('$policy', $policy)
            ->setArgument('$responder', $responder)
            ->setArgument('$errorPage', $config['error_page']);
    }
}
