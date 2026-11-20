<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Audit;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Tests\Integration\DoctrineTestCase;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\Tests\Support\TestManagerRegistry;
use Nowo\BlogKitBundle\User\BlogUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function str_repeat;

/**
 * Audit ON-R1 (built from the auditor's re-verify probe): a flush refused by {@see BlogUserBlameListener} leaves nothing
 * behind. In a kept-alive worker whose host never clears its manager, the next user's flush must not save the refused
 * edit (fields, translations or tags) under that user's name.
 *
 * Since 1.12.0 (audit XD-3) only the bundle's own manager (private or dedicated) is closed; one of your managers (host
 * mode) stays open: the blog's work is forgotten and your own pending changes are kept.
 */
final class AuditOnRefusedFlushTest extends DoctrineTestCase
{
    private TokenStorage $tokenStorage;

    protected function userReference(): string
    {
        return BlogUsers::ID;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenStorage = new TokenStorage();
    }

    /** The private manager of `doctrine.private_entity_manager: true` is in no registry: it is the bundle's own. */
    #[Test]
    public function aRefusedEditNeverReachesTheDatabaseThroughALaterFlush(): void
    {
        $other = new EntityManager($this->connection, $this->entityManager->getConfiguration(), new EventManager());
        $this->listen(new TestManagerRegistry($other, $this->connection));
        $this->assertOwnManagerIsClosed();
    }

    /** The dedicated `doctrine.entity_manager` is the bundle's own, although it is in the registry. */
    #[Test]
    public function aRefusedFlushClosesTheDedicatedManager(): void
    {
        $this->listen($this->registry, 'default');
        $this->assertOwnManagerIsClosed();
    }

    /**
     * Audit XD-3: in host mode the flushing manager is yours; it stays open. Nothing of the refused blog write (fields,
     * a new article, a translation's orphan removal, the tags) is written by your next flush on the same manager, and
     * your own pending change is.
     */
    #[Test]
    public function aRefusedFlushNeverClosesYourManager(): void
    {
        $this->listen($this->registry);
        [$id, $article] = $this->seed();

        $this->login(str_repeat('x', 129));
        $article->setSlug('defaced');
        $article->getTranslation('es')?->setTitle('Defaced');
        $removed = $article->getTranslation('en');
        self::assertNotNull($removed);
        $article->getTranslations()->removeElement($removed);
        $spam = new BlogTag()->setSlug('spam');
        $this->entityManager->persist($spam);
        $article->setTags([$spam]);
        $this->entityManager->persist(new BlogArticle()->setSlug('intruder'));
        $this->entityManager->persist(new MappedTestUser()->setEmail('host@example.com'));
        try {
            $this->entityManager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        self::assertTrue($this->entityManager->isOpen(), 'your manager is never closed');
        self::assertFalse($this->entityManager->contains($article), 'the refused blog entities are forgotten');
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledCollectionUpdates());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledCollectionDeletions());

        // The next flush on the same manager, by another user: only your own pending change is written.
        $this->login('bob');
        $this->entityManager->flush();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM blog_kit_test_user WHERE email = ?', ['host@example.com']));
        $this->assertUntouched($id);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article_translation WHERE translatable_id = ?', [$id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article WHERE slug = ?', ['intruder']));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_tag WHERE slug = ?', ['spam']));

        // A later find() reads the row again, and an allowed edit is saved under the new user's name.
        $fresh = $this->entityManager->find(BlogArticle::class, $id);
        self::assertInstanceOf(BlogArticle::class, $fresh);
        self::assertNotSame($article, $fresh);
        self::assertSame('victim', $fresh->getSlug());
        $fresh->setSlug('edited');
        $this->entityManager->flush();
        self::assertSame('edited', $this->connection->fetchOne('SELECT slug FROM content_blog_article WHERE id = ?', [$id]));
        self::assertSame('bob', $this->connection->fetchOne('SELECT updated_by_id FROM content_blog_article WHERE id = ?', [$id]));
    }

    /** Audit XD-3: a refused removal of a blog entity on your manager is forgotten, not run by the next flush. */
    #[Test]
    public function aRefusedRemovalIsForgottenOnYourManager(): void
    {
        $this->listen(null);
        [$id, $article] = $this->seed();

        $this->login(str_repeat('x', 129));
        $article->setSlug('defaced');
        $translation = $article->getTranslation('es');
        self::assertNotNull($translation);
        $this->entityManager->remove($translation);
        $article->getTags()->clear();
        self::assertNotSame([], $this->entityManager->getUnitOfWork()->getScheduledCollectionDeletions());
        try {
            $this->entityManager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        self::assertTrue($this->entityManager->isOpen());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledEntityDeletions());

        $this->login('bob');
        $this->entityManager->flush();
        $this->assertUntouched($id);
    }

    private function assertOwnManagerIsClosed(): void
    {
        [$id, $article] = $this->seed();
        // An editor whose id is refused edits fields, a translation and the tags.
        $this->login(str_repeat('x', 129));
        $article->setSlug('defaced');
        $article->getTranslation('es')?->setTitle('Defaced');
        $article->setTags([]);
        try {
            $this->entityManager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        self::assertFalse($this->entityManager->isOpen(), 'closed like after any failed flush');
        self::assertSame(0, $this->entityManager->getUnitOfWork()->size(), 'nothing of the refused write is kept');

        // The next request: the manager was recreated (DoctrineBundle / BlogKitWorkerStateSubscriber), another user flushes.
        $next = new EntityManager($this->connection, $this->entityManager->getConfiguration(), $this->entityManager->getEventManager());
        $this->login('bob');
        $next->persist(new BlogTag()->setSlug('symfony'));
        $next->flush();

        $this->assertUntouched($id);
    }

    private function listen(?TestManagerRegistry $registry, ?string $dedicatedManager = null): void
    {
        $this->entityManager->getEventManager()->addEventListener(Events::onFlush, new BlogUserBlameListener(new BlogUsers(BlogUsers::ID, $this->tokenStorage), $registry, $dedicatedManager));
    }

    /**
     * @return array{int, BlogArticle}
     */
    private function seed(): array
    {
        $this->login('alice');
        $tag     = new BlogTag()->setSlug('php');
        $article = new BlogArticle()->setSlug('victim')->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle('Victim'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle('Victim EN'));
        $article->addTag($tag);
        $this->entityManager->persist($tag);
        $this->entityManager->persist($article);
        $this->entityManager->flush();
        $id = $article->getId();
        self::assertIsInt($id);

        return [$id, $article];
    }

    private function assertUntouched(int $id): void
    {
        self::assertSame('victim', $this->connection->fetchOne('SELECT slug FROM content_blog_article WHERE id = ?', [$id]));
        self::assertSame('Victim', $this->connection->fetchOne('SELECT title FROM content_blog_article_translation WHERE translatable_id = ? AND locale = ?', [$id, 'es']));
        self::assertSame('alice', $this->connection->fetchOne('SELECT updated_by_id FROM content_blog_article WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article_tag WHERE blog_article_id = ?', [$id]));
    }

    private function login(string $id): void
    {
        $user = new PlainHostUser($id, 'user');
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
