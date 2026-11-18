<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitConflictSubscriber;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.10.0 (REQ-RUNTIME-003 rule 6): lock conflicts of the bundle's routes answer 409 + X-Conflict-Code, with
 * Retry-After for CONFLICT_001 only; unique violations, connection loss and host routes are left alone.
 */
final class BlogKitConflictSubscriberTest extends TestCase
{
    private const string CONTROLLER = 'Nowo\\BlogKitBundle\\Controller\\PublicCommentController::create';

    #[Test]
    public function itListensBeforeTheFrameworkErrorListener(): void
    {
        self::assertSame([KernelEvents::EXCEPTION => ['onKernelException', -64]], BlogKitConflictSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function aRunnerConflictWithNothingCommittedAnswersConflict001WithRetryAfter(): void
    {
        $event = $this->event(TransientStorageFailure::fromPrevious($this->deadlock(), 3));
        new BlogKitConflictSubscriber()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'), 'the policy\'s value (the responder reads hints of write units only)');
    }

    #[Test]
    public function aWrappedDbalDeadlockIsMappedAndJsonKeepsTheCodeInTheBody(): void
    {
        $request = $this->request();
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $event = $this->event(new RuntimeException('wrapped', 0, $this->deadlock()), $request);
        new BlogKitConflictSubscriber(new ConflictResponsePolicy(503))->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode(), 'the host may keep 503');
        self::assertSame('1', $response->headers->get('Retry-After'));
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CONFLICT_001', $body['code']);
    }

    #[Test]
    public function aConflictAfterACommitOfTheRequestAnswersConflict002WithoutRetryAfter(): void
    {
        $ledger  = new CommitLedger();
        $request = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();

        $event = $this->event(TransientStorageFailure::fromPrevious($this->deadlock()), $request);
        new BlogKitConflictSubscriber()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    #[Test]
    public function theErrorPageModeThrowsAnHttpExceptionWithTheHeaders(): void
    {
        $event = $this->event(TransientStorageFailure::fromPrevious($this->deadlock(), 2));
        new BlogKitConflictSubscriber(errorPage: true)->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(409, $exception->getStatusCode());
        self::assertSame(BlogKitConflictSubscriber::MESSAGE, $exception->getMessage());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '2'], $exception->getHeaders());
        self::assertNull($event->getResponse());

        $event = $this->event($this->deadlock());
        new BlogKitConflictSubscriber(errorPage: true)->onKernelException($event);
        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $exception->getHeaders(), 'a bare DBAL failure has no hint');
    }

    #[Test]
    public function uniqueViolationsConnectionLossAndOtherFailuresAreNotConflicts(): void
    {
        foreach ([
            new UniqueConstraintViolationException($this->driverException('23000'), null),
            new ConnectionLost($this->driverException('08S01'), null),
            new RuntimeException('domain'),
        ] as $failure) {
            $event = $this->event($failure);
            new BlogKitConflictSubscriber()->onKernelException($event);

            self::assertNull($event->getResponse(), $failure::class);
            self::assertSame($failure, $event->getThrowable());
        }
    }

    #[Test]
    public function hostRoutesAreNeverAnswered(): void
    {
        foreach (['App\\Controller\\BlogController::index', null] as $controller) {
            $request = Request::create('/blog', 'POST');
            $request->attributes->set('_controller', $controller);
            $event = $this->event(TransientStorageFailure::fromPrevious($this->deadlock()), $request);
            new BlogKitConflictSubscriber()->onKernelException($event);

            self::assertNull($event->getResponse());
        }
    }

    private function request(): Request
    {
        $request = Request::create('/blog/post/comments', 'POST');
        $request->attributes->set('_controller', self::CONTROLLER);

        return $request;
    }

    private function event(Throwable $failure, ?Request $request = null): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request ?? $this->request(), HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException($this->driverException('40001', 1213), null);
    }

    private function driverException(string $sqlState, int $code = 0): DriverException
    {
        return new class('driver', $code, $sqlState) extends Exception implements DriverException {
            public function __construct(string $message, int $code, private readonly string $sqlState)
            {
                parent::__construct($message, $code);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }
}
