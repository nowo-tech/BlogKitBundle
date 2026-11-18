<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Doctrine\DBAL\Exception\RetryableException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;

/**
 * Since 1.10.0 (REQ-RUNTIME-003 rule 6): a lock conflict of a write of a bundle route answers the platform conflict
 * answer instead of a 500.
 *
 * A transient failure ({@see TransientStorageFailure} of the bundle's runner, or a DBAL {@see RetryableException}:
 * deadlock, lock wait timeout, serialization failure) answers **409** (configurable to 503) with
 * `X-Conflict-Code` and a code ({@see ConflictCode::forRequest()}): `CONFLICT_001` and `Retry-After` when nothing was
 * committed, `CONFLICT_002` without `Retry-After` when part of the request was (the request's commit mark of
 * transaction-doctrine >= 1.6, or a lost savepoint). The body is {@see ConcurrencyConflictResponder::respond()}: JSON
 * `{"error", "code", "message"}` for JSON / XHR requests, else the "try again" page. With
 * `conflict_response.error_page: true` the failure becomes an HttpException with the same status and headers,
 * rendered by your error page. Wiring: {@see ConflictResponsePass}.
 *
 * Not conflicts, never mapped: unique violations (the admin forms and the tag writers handle the expected race where
 * it happens, any other one is a real error) and connection loss.
 *
 * Only the bundle's own controllers (`Nowo\BlogKitBundle\Controller\`), never a host route. Priority -64: after the
 * application's own exception listeners, before the framework's error listener (-128). Stateless.
 */
final readonly class BlogKitConflictSubscriber implements EventSubscriberInterface
{
    /** Controller namespace of the bundle. */
    private const string CONTROLLER_PREFIX = 'Nowo\\BlogKitBundle\\Controller\\';

    /** Generic, client-safe text of the error-page mode. */
    public const string MESSAGE = 'The same data was being changed at the same time. Try again.';

    private ConflictResponsePolicy $policy;

    private ConcurrencyConflictResponder $responder;

    /**
     * @param ConflictResponsePolicy|null $policy status (409 or 503) and Retry-After; null = 409 + Retry-After: 1
     * @param ConcurrencyConflictResponder|null $responder builds the answer; null = a plain one with $policy
     * @param bool $errorPage whether your error page renders the answer
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        ?ConcurrencyConflictResponder $responder = null,
        private bool $errorPage = false,
    ) {
        $this->policy    = $policy ?? new ConflictResponsePolicy();
        $this->responder = $responder ?? new ConcurrencyConflictResponder($this->policy);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');

        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return;
        }

        $failure   = $event->getThrowable();
        $transient = $this->transientFailure($failure);

        if (!$transient instanceof Throwable) {
            return;
        }

        if ($this->errorPage) {
            $hint = $transient instanceof TransientStorageFailure ? $transient->retryAfterSeconds() : null;
            $event->setThrowable($this->policy->httpExceptionFor(ConflictCode::forRequest($request, $failure), self::MESSAGE, $failure, $hint));

            return;
        }

        $event->setResponse($this->responder->respond($request, $failure));
    }

    /**
     * The transient failure in the chain (the runner's, or a DBAL retryable one); null when the failure is not one.
     */
    private function transientFailure(Throwable $failure): ?Throwable
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure || $current instanceof RetryableException) {
                return $current;
            }
        }

        return null;
    }
}
