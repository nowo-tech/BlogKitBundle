<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner;
use Nowo\BlogKitBundle\Worker\BlogKitOwnEntityManagerSubscriber;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Since 1.12.0 (REQ-ARCH-001 rule 6 deprecation layer): reports your pending changes that a blog write flushes while
 * the bundle writes through your entity manager (`doctrine.private_entity_manager` unset or false, no
 * `doctrine.entity_manager`). 2.0 writes through a private entity manager and no longer flushes them.
 *
 * Called right before each flush of your manager by the bundle ({@see BlogKitTransactionRunner}).
 * For each entity class outside `Nowo\BlogKitBundle\Entity` with pending work in that manager, the host-flush probe
 * of nowo-tech/transaction-doctrine ({@see HostFlushProbe}) triggers one `E_USER_DEPRECATED` per class and unit of
 * work: "Your pending changes to App\X were flushed by NowoBlogKitBundle; 2.0 no longer does." The bundle never changes
 * one of your entities itself, so every class reported is your own work: the list is your migration inventory.
 *
 * Disabled (nothing is inspected) in the private mode and with `doctrine.entity_manager`; a
 * {@see PrivateEntityManager} is never inspected.
 *
 * Read-only: it never flushes, clears, closes or resets a manager (REQ-RUNTIME-003), and a failure of the probe never
 * breaks the write (it is logged). The probe is the shared `nowo_transaction_doctrine.host_flush_probe` when the
 * application registers NowoTransactionDoctrineBundle, else one of its own. Its "already reported" classes are reset at
 * every outermost unit-of-work boundary (request, Messenger message, console command:
 * {@see BlogKitOwnEntityManagerSubscriber}) and at `kernel.reset`, so a FrankenPHP worker with the kernel reset off
 * starts every unit clean.
 *
 * @internal registered by the bundle; not an extension point
 */
final readonly class BlogKitHostFlushReporter implements ResetInterface
{
    /** The bundle named in the deprecation. */
    public const string BUNDLE_NAME = 'NowoBlogKitBundle';

    /** The bundle's entity namespace: its classes are never reported. */
    public const array OWN_NAMESPACES = ['Nowo\\BlogKitBundle\\Entity'];

    private HostFlushProbe $probe;

    /**
     * @param bool $enabled true only while the bundle writes through your manager
     * @param HostFlushProbe|null $probe transaction-doctrine's shared probe (null: one of its own)
     */
    public function __construct(
        private bool $enabled = false,
        ?HostFlushProbe $probe = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->probe = $probe ?? new HostFlushProbe();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Right before the bundle flushes `$entityManager`: reports your pending changes it would write.
     */
    public function beforeFlush(EntityManagerInterface $entityManager): void
    {
        if (!$this->enabled || $entityManager instanceof PrivateEntityManager) {
            return;
        }

        try {
            $this->probe->beforeHostFlush($entityManager, self::BUNDLE_NAME, self::OWN_NAMESPACES);
        } catch (Throwable $exception) {
            // The probe is a migration aid: it must never break the write it observes.
            $this->logger?->warning('NowoBlogKitBundle: the host-flush probe failed ({exception}); the write goes on.', ['exception' => $exception::class]);
        }
    }

    /** A new unit of work (request, message, command) starts: nothing was reported yet. */
    public function reset(): void
    {
        $this->probe->reset();
    }
}
