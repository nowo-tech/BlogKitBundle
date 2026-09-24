<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\EventSubscriber\BlogArticlePublishedDoctrineSubscriber;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitWorkerStateSubscriber;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Service\BlogSettingsProvider;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Simulates consecutive requests served by the same worker without `services_resetter`.
 */
final class BlogKitWorkerStateSubscriberTest extends DoctrineTestCase
{
    private BlogSettingsRepository $settingsRepository;
    private BlogSettingsProvider $settingsProvider;
    private BlogArticleRepository $articleRepository;
    private BlogTagRepository $tagRepository;
    private BlogArticlePublishedDoctrineSubscriber $publishedSubscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsRepository  = new BlogSettingsRepository($this->registry);
        $this->settingsProvider    = new BlogSettingsProvider($this->settingsRepository);
        $this->articleRepository   = new BlogArticleRepository($this->registry, LocaleTestSupport::create());
        $this->tagRepository       = new BlogTagRepository($this->registry);
        $this->publishedSubscriber = new BlogArticlePublishedDoctrineSubscriber(
            $this->createMock(EventDispatcherInterface::class),
        );
    }

    #[Test]
    public function subscribesToMainRequestBeforeRoutingAndSecurity(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 4096]],
            BlogKitWorkerStateSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function secondRequestSeesSettingsAndTagsChangedByAnotherWorkerWithoutReset(): void
    {
        $this->createSettings();
        $this->createArticle('first', tags: [$this->createTag('php')]);
        $subscriber = $this->createSubscriber();

        $subscriber->onKernelRequest($this->requestEvent());
        self::assertSame(6, $this->settingsProvider->perPage());
        self::assertCount(1, $this->tagRepository->findPublishedTagSummaries('es'));

        $this->updatePerPageFromAnotherWorker(12);
        $this->createArticle('second', tags: [$this->createTag('symfony')]);

        self::assertSame(6, $this->settingsProvider->perPage(), 'Memo is request-scoped, not call-scoped.');

        $subscriber->onKernelRequest($this->requestEvent());

        self::assertSame(12, $this->settingsProvider->perPage());
        self::assertSame(12, $this->settingsRepository->getSingleton()->getPerPage());
        self::assertCount(2, $this->tagRepository->findPublishedTagSummaries('es'));
    }

    #[Test]
    public function subRequestsKeepTheMainRequestMemo(): void
    {
        $this->createSettings();
        $subscriber = $this->createSubscriber();

        $subscriber->onKernelRequest($this->requestEvent());
        self::assertSame(6, $this->settingsProvider->perPage());

        $this->updatePerPageFromAnotherWorker(12);
        $subscriber->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));

        self::assertSame(6, $this->settingsProvider->perPage());
    }

    #[Test]
    public function publishBufferFromAFailedFlushIsDroppedOnTheNextRequest(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');
        $this->publishedSubscriber = new BlogArticlePublishedDoctrineSubscriber($dispatcher);

        $this->entityManager->persist((new BlogArticle())->setSlug('rolled-back')->setPublished(true));
        $this->publishedSubscriber->onFlush(new OnFlushEventArgs($this->entityManager));
        $this->clearEntityManager();

        $this->createSubscriber()->onKernelRequest($this->requestEvent());

        $this->publishedSubscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    #[Test]
    public function closedEntityManagerIsResetByNameOnTheNextMainRequest(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(BlogArticle::class)->willReturn($closed);
        $registry->method('getManagerNames')->willReturn([
            'audit' => 'doctrine.orm.audit_entity_manager',
            'blog'  => 'doctrine.orm.blog_entity_manager',
        ]);
        $registry->method('getManager')->willReturnCallback(
            fn (?string $name): ObjectManager => $name === 'blog' ? $closed : $this->createMock(ObjectManager::class),
        );
        $registry->expects(self::once())->method('resetManager')->with('blog');

        $this->createSubscriber($registry)->onKernelRequest($this->requestEvent());
    }

    #[Test]
    public function closedEntityManagerIsNotResetOnSubRequests(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagerForClass');
        $registry->expects(self::never())->method('resetManager');

        $this->createSubscriber($registry)->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));
    }

    #[Test]
    public function openOrUnknownEntityManagersAreLeftUntouched(): void
    {
        $open = $this->createMock(EntityManagerInterface::class);
        $open->method('isOpen')->willReturn(true);

        $closedButUnnamed = $this->createMock(EntityManagerInterface::class);
        $closedButUnnamed->method('isOpen')->willReturn(false);

        foreach ([$open, null, $closedButUnnamed] as $manager) {
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->method('getManagerForClass')->willReturn($manager);
            $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
            $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));
            $registry->expects(self::never())->method('resetManager');

            $this->createSubscriber($registry)->onKernelRequest($this->requestEvent());
        }
    }

    private function createSubscriber(?ManagerRegistry $registry = null): BlogKitWorkerStateSubscriber
    {
        return new BlogKitWorkerStateSubscriber(
            $registry ?? $this->registry,
            $this->settingsRepository,
            $this->settingsProvider,
            $this->articleRepository,
            $this->tagRepository,
            $this->publishedSubscriber,
        );
    }

    private function requestEvent(int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/blog'), $type);
    }

    private function updatePerPageFromAnotherWorker(int $perPage): void
    {
        $metadata = $this->entityManager->getClassMetadata(BlogSettings::class);

        $this->connection->executeStatement(sprintf(
            'UPDATE %s SET %s = %d',
            $metadata->getTableName(),
            $metadata->getColumnName('perPage'),
            $perPage,
        ));
    }
}
