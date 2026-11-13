<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Worker\BlogKitOwnEntityManagerSubscriber;
use Nowo\BlogKitBundle\Worker\UnitOfWorkBoundaries;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Starts every main request with empty bundle memos, even when `services_resetter` does not run
 * between requests (long-running workers such as FrankenPHP or RoadRunner).
 *
 * Since 1.9.0 the same memos are also emptied when a Messenger message is received, handled or failed, and when the
 * OUTERMOST console command starts and terminates (judged by {@see UnitOfWorkBoundaries}), so a long-running consumer
 * or command never reuses the settings, tags or publish buffer of the previous message or command.
 *
 * On a main request it also recovers the blog entity manager when a previous request closed it after a failed flush.
 * It never clears an open entity manager: the bundle's OWN manager (`doctrine.entity_manager`) is cleared by
 * {@see BlogKitOwnEntityManagerSubscriber}; detaching application entities from the host's managers stays the host's
 * job (REQ-RUNTIME-002 rule 7).
 */
final readonly class BlogKitWorkerStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private BlogSettingsRepository $blogSettingsRepository,
        private BlogSettingsProvider $blogSettingsProvider,
        private BlogArticleRepository $blogArticleRepository,
        private BlogTagRepository $blogTagRepository,
        private BlogArticlePublishedDoctrineSubscriber $blogArticlePublishedDoctrineSubscriber,
        private ?UnitOfWorkBoundaries $boundaries = null,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                                   => ['onKernelRequest', 4096],
            UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED => ['resetMemos', 4096],
            UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED  => ['resetMemos', -1024],
            UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_FAILED   => ['resetMemos', -1024],
            UnitOfWorkBoundaries::CONSOLE_COMMAND                   => ['onConsoleBoundary', 4096],
            UnitOfWorkBoundaries::CONSOLE_TERMINATE                 => ['onConsoleBoundary', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->resetMemos();
        $this->recoverClosedEntityManager();
    }

    /**
     * Since 1.9.0: start or end of a console command. Empties the memos only for the outermost command (a command run
     * in-process from another command, a message handler or a controller keeps the outer unit's memos).
     */
    public function onConsoleBoundary(object $event): void
    {
        if (!$this->boundaries instanceof UnitOfWorkBoundaries || $this->boundaries->isOutermost($event)) {
            $this->resetMemos();
        }
    }

    /**
     * Empties the settings / tag memos and the publish buffer (no entity manager is touched).
     */
    public function resetMemos(): void
    {
        $this->blogSettingsRepository->reset();
        $this->blogSettingsProvider->reset();
        $this->blogArticleRepository->reset();
        $this->blogTagRepository->reset();
        $this->blogArticlePublishedDoctrineSubscriber->reset();
    }

    private function recoverClosedEntityManager(): void
    {
        $manager = $this->managerRegistry->getManagerForClass(BlogArticle::class);

        if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
            return;
        }

        foreach (array_keys($this->managerRegistry->getManagerNames()) as $name) {
            if ($this->managerRegistry->getManager($name) === $manager) {
                $this->managerRegistry->resetManager($name);

                return;
            }
        }
    }
}
