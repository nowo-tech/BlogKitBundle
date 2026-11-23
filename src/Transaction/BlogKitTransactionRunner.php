<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Transaction;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticlePublishedDoctrineSubscriber;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitConflictSubscriber;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Since 1.10.0 (REQ-RUNTIME-003 rules 1 to 3): every write of the bundle runs here, as one transaction on the blog
 * entity manager, through nowo-tech/transaction-doctrine.
 *
 * - {@see run()}: a unit of work whose operation makes the changes (persist, remove, setters); the runner flushes and
 *   commits, with {@see DoctrineTransactionRunner::forEntityManager()} on the bundle's manager: its private manager
 *   (since 2.0 the only mode without `doctrine.entity_manager`) or the dedicated `doctrine.entity_manager`.
 * - {@see runRereadingOnDuplicate()}: the same, run once more after a unique violation: the expected race of a
 *   check-then-insert backed by a unique key (two writers creating the same tag). The second attempt reads again and
 *   finds the winner's row; a second violation is a real error.
 * - {@see flushPending()}: the admin forms bind the managed entity before the save, so their changes are already
 *   pending: they are flushed in one transaction on the manager's connection
 *   ({@see DoctrineTransactionRunner::forConnection()}).
 *
 * A deadlock, lock wait timeout or serialization failure leaves as {@see TransientStorageFailure} (answered 409 +
 * `CONFLICT_001` / `CONFLICT_002` on the bundle's routes, see {@see BlogKitConflictSubscriber});
 * any other failure is rethrown unchanged. After a failure the levels the unit opened are rolled back (savepoint
 * aware; inside your transaction, to the savepoint). {@see BlogArticlePublishedDoctrineSubscriber} holds the publish
 * events of the unit until it committed.
 *
 * Since 2.0 the container always builds it on the bundle's private manager (the private registry, a failure closes
 * and drops only the private manager; yours is never flushed, cleared or touched) or on the dedicated manager. The
 * 1.x mode in which it flushed your default manager, the 1.10 "your manager must be clean before a blog write"
 * `LogicException` of that mode and the 1.12 host-flush probe are gone with it. Built directly with neither a
 * private source manager nor `$ownManagerName`, it treats the manager it was given as the caller's
 * ({@see DoctrineTransactionRunner::forHostEntityManager()}: never cleared or reset; it must be clean when a unit
 * starts; a closed one is replaced through the registry, as in 1.x); the bundle's own wiring never does that.
 *
 * Stateless (the transaction-doctrine runner is built per call): safe in a FrankenPHP worker with the kernel kept.
 */
final readonly class BlogKitTransactionRunner implements TransactionRunnerInterface
{
    /**
     * @param string|null $ownManagerName `nowo_blog_kit.doctrine.entity_manager`: a manager dedicated to the blog (null: the host's)
     * @param bool $direct internal: {@see unmanaged()}
     * @param string|null $privateSourceManager since 1.12.0: with the private manager, the name your manager the
     *                                          private one is created on has in the registry (null or '': not the
     *                                          private manager)
     * @param object|null $hostFlushReporter deprecated since 2.0, ignored (the 1.12 host-flush probe was removed with the
     *                                       mode that flushed your manager); removed in 3.0
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        #[Autowire('%nowo_blog_kit.doctrine.entity_manager%')]
        private ?string $ownManagerName = null,
        private ?BlogArticlePublishedDoctrineSubscriber $publishedEvents = null,
        private ?LoggerInterface $logger = null,
        private int $retryAfterSeconds = 1,
        private bool $direct = false,
        #[Autowire('%nowo_blog_kit.private_entity_manager.source_manager%')]
        private ?string $privateSourceManager = null,
        ?object $hostFlushReporter = null,
    ) {
        unset($hostFlushReporter);
    }

    /**
     * The 1.9 behaviour (the operation, then a bare flush; no transaction of its own): the fallback of the services
     * built without a runner (direct instantiation, tests).
     *
     * @internal
     */
    public static function unmanaged(EntityManagerInterface $entityManager): self
    {
        return new self($entityManager, direct: true);
    }

    /**
     * Runs $operation and flushes its changes in one transaction.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @throws TransientStorageFailure When the unit hit a deadlock, lock wait timeout or serialization failure
     * @throws LogicException Built directly on a caller's manager (no manager name), when it has pending changes before the unit
     *
     * @return T
     */
    public function run(callable $operation): mixed
    {
        if ($this->direct) {
            $result = $operation();
            $this->entityManager->flush();

            return $result;
        }

        return $this->holdingPublishedEvents(fn (): mixed => $this->unitRunner()->run($operation));
    }

    /**
     * {@see run()}, run once more when the unit failed on a unique violation (a concurrent writer inserted the same
     * key first): $operation must read again what it checks (find the tag by slug, then create it only if missing).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function runRereadingOnDuplicate(callable $operation): mixed
    {
        try {
            return $this->run($operation);
        } catch (Throwable $throwable) {
            if (!$throwable instanceof UniqueConstraintViolationException) {
                throw $throwable;
            }

            return $this->run($operation);
        }
    }

    /**
     * Flushes the changes already made on managed entities (a bound admin form) in one transaction.
     *
     * @throws TransientStorageFailure When the flush hit a deadlock, lock wait timeout or serialization failure
     */
    public function flushPending(): void
    {
        if ($this->direct) {
            $this->entityManager->flush();

            return;
        }

        $this->holdingPublishedEvents(fn (): mixed => DoctrineTransactionRunner::forConnection($this->entityManager->getConnection(), $this->retryAfterSeconds, $this->logger)
            ->run(function (): null {
                $this->entityManager->flush();

                return null;
            }));
    }

    /**
     * @template T
     *
     * @param callable(): T $unit
     *
     * @return T
     */
    private function holdingPublishedEvents(callable $unit): mixed
    {
        $marker = $this->publishedEvents?->hold() ?? 0;

        try {
            $result = $unit();
        } catch (Throwable $throwable) {
            $this->publishedEvents?->discard($marker);

            throw $throwable;
        }

        $this->publishedEvents?->release();

        return $result;
    }

    private function unitRunner(): DoctrineTransactionRunner
    {
        if ($this->privateSourceManager !== null && $this->privateSourceManager !== '') {
            return DoctrineTransactionRunner::forEntityManager($this->entityManager, $this->registry, $this->privateSourceManager, $this->retryAfterSeconds, $this->logger);
        }

        if ($this->ownManagerName !== null) {
            return DoctrineTransactionRunner::forEntityManager($this->entityManager, $this->registry, $this->ownManagerName, $this->retryAfterSeconds, $this->logger);
        }

        // Built directly on a caller's manager (never the bundle's wiring since 2.0): as in 1.x, never cleared or reset;
        // a closed one is replaced through the registry when the manager that maps the blog entities has a name there.
        $name = $this->callersManagerName();

        return DoctrineTransactionRunner::forHostEntityManager($this->entityManager, $name !== null ? $this->registry : null, $name, $this->retryAfterSeconds, $this->logger);
    }

    /**
     * The registry name of the manager that maps the blog entities (null when there is no registry or no name).
     */
    private function callersManagerName(): ?string
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return null;
        }

        $manager = $this->registry->getManagerForClass(BlogArticle::class);

        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if ($this->registry->getManager($name) === $manager) {
                return $name;
            }
        }

        return null;
    }
}
