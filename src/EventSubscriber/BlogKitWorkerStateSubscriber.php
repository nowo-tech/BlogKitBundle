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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Starts every main request with empty bundle memos, even when `services_resetter` does not run
 * between requests (long-running workers such as FrankenPHP or RoadRunner).
 *
 * It also recovers the blog entity manager when a previous request closed it after a failed flush.
 * It never clears an open entity manager: detaching application entities stays the host's job.
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
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->blogSettingsRepository->reset();
        $this->blogSettingsProvider->reset();
        $this->blogArticleRepository->reset();
        $this->blogTagRepository->reset();
        $this->blogArticlePublishedDoctrineSubscriber->reset();

        $this->recoverClosedEntityManager();
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
