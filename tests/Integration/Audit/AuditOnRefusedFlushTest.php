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
use Nowo\BlogKitBundle\Tests\Support\HostRef\HostArticleLink;
use Nowo\BlogKitBundle\Tests\Support\HostRef\HostParent;
use Nowo\BlogKitBundle\Tests\Support\MappedTestUser;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\Tests\Support\TestManagerRegistry;
use Nowo\BlogKitBundle\User\BlogUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function str_repeat;

/**
 * Audit ON-R1 (built from the auditor's re-verify probe): a flush refused by {@see BlogUserBlameListener} leaves nothing
 * behind. In a kept-alive worker whose host never clears its manager, the next user's flush must not save the refused
 * edit (fields, translations or tags) under that user's name.
 *
 * Since 1.12.0 (audit XD-3) only the bundle's own manager (private or dedicated) is closed; one of your managers (host
 * mode) stays open: the blog's work is undone (managed entities restored, new ones forgotten) and your own pending
 * changes are kept.
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
     * Audits XD-3 / XD2-1: in host mode the flushing manager is yours; it stays open. Nothing of the refused blog write
     * (fields, a new article, a new tag, a translation's orphan removal, the tags) is written by your next flush on the
     * same manager, the blog entities stay managed (restored), and your own pending changes are written, including
     * your entities pointing at the article and at the refused new entities (unlinked from these).
     */
    #[Test]
    public function aRefusedFlushNeverClosesYourManager(): void
    {
        $this->listen($this->registry);
        [$id, $article] = $this->seed();
        $parent         = new HostParent()->addChild()->addChild();
        $this->entityManager->persist($parent);
        $this->entityManager->flush();

        // Your own pending work of every kind.
        $parent->name = 'host-dirty';
        $parent->children->clear();
        $this->entityManager->persist(new MappedTestUser()->setEmail('host@example.com'));
        $toArticle = new HostArticleLink($article);
        $this->entityManager->persist($toArticle);

        // The refused blog work.
        $this->login(str_repeat('x', 129));
        $article->setSlug('defaced');
        $article->getTranslation('es')?->setTitle('Defaced');
        $removed = $article->getTranslation('en');
        self::assertNotNull($removed);
        $article->getTranslations()->removeElement($removed);
        $spam = new BlogTag()->setSlug('spam');
        $this->entityManager->persist($spam);
        $article->setTags([$spam]);
        $intruder = new BlogArticle()->setSlug('intruder');
        $this->entityManager->persist($intruder);
        $toIntruder = new HostArticleLink($intruder);
        $toIntruder->tags->add($spam);
        $this->entityManager->persist($toIntruder);
        try {
            $this->entityManager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        self::assertTrue($this->entityManager->isOpen(), 'your manager is never closed');
        self::assertTrue($this->entityManager->contains($article), 'a managed blog entity is restored, not detached');
        self::assertSame('victim', $article->getSlug());
        self::assertFalse($this->entityManager->contains($intruder), 'a new blog entity is forgotten');
        self::assertNull($toIntruder->article, 'and unlinked from your entities');
        self::assertCount(0, $toIntruder->tags);
        foreach ($this->entityManager->getUnitOfWork()->getScheduledCollectionUpdates() as $collection) {
            self::assertNotInstanceOf(BlogArticle::class, $collection->getOwner(), 'only your own collection changes stay scheduled');
        }

        // The next flush on the same manager, by another user: only your own pending changes are written.
        $this->login('bob');
        $this->entityManager->flush();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM blog_kit_test_user WHERE email = ?', ['host@example.com']));
        self::assertSame('host-dirty', $this->connection->fetchOne('SELECT name FROM host_ref_parent WHERE id = ?', [$parent->id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM host_ref_child'));
        self::assertSame($id, (int) $this->connection->fetchOne('SELECT article_id FROM host_ref_article_link WHERE id = ?', [$toArticle->id]));
        self::assertNull($this->connection->fetchOne('SELECT article_id FROM host_ref_article_link WHERE id = ?', [$toIntruder->id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM host_ref_article_link_tag'));
        $this->assertUntouched($id);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article_translation WHERE translatable_id = ?', [$id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article WHERE slug = ?', ['intruder']));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_tag WHERE slug = ?', ['spam']));

        // The restored article reads its collections again, and an allowed edit is saved under the new user's name.
        self::assertSame($article, $this->entityManager->find(BlogArticle::class, $id));
        self::assertSame('Victim', $article->getTranslation('es')?->getTitle());
        self::assertCount(2, $article->getTranslations());
        self::assertSame(['php'], array_map(static fn (BlogTag $tag): string => $tag->getSlug(), $article->getTags()->toArray()));
        $article->setSlug('edited');
        $this->entityManager->flush();
        self::assertSame('edited', $this->connection->fetchOne('SELECT slug FROM content_blog_article WHERE id = ?', [$id]));
        self::assertSame('bob', $this->connection->fetchOne('SELECT updated_by_id FROM content_blog_article WHERE id = ?', [$id]));
    }

    /**
     * Audit XD2-1 (the auditor's repro): only the slug is refused; the untouched translations and your entity pointing at
     * the article stay valid, so your next flushes work (no "A new entity was found through the relationship").
     */
    #[Test]
    public function aRefusedFieldEditLeavesYourManagerUsable(): void
    {
        $this->listen($this->registry);
        [$id, $article] = $this->seed();
        $link           = new HostArticleLink($article);
        $this->entityManager->persist($link);

        $this->login(str_repeat('x', 129));
        $article->setSlug('defaced');
        try {
            $this->entityManager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        $this->login('bob');
        $this->entityManager->flush();
        $this->entityManager->persist(new MappedTestUser()->setEmail('later@example.com'));
        $this->entityManager->flush();

        $this->assertUntouched($id);
        self::assertSame($id, (int) $this->connection->fetchOne('SELECT article_id FROM host_ref_article_link WHERE id = ?', [$link->id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM blog_kit_test_user WHERE email = ?', ['later@example.com']));
    }

    /** Audit XD-3: a refused removal of a blog entity on your manager is undone, not run by the next flush. */
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
        self::assertTrue($this->entityManager->contains($translation), 'managed again');

        $this->login('bob');
        $this->entityManager->flush();
        $this->assertUntouched($id);
        self::assertSame('Victim', $article->getTranslation('es')?->getTitle());
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
