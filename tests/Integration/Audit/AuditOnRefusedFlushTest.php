<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Audit;

use DateTimeImmutable;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Tests\Integration\DoctrineTestCase;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
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
        $this->entityManager->getEventManager()->addEventListener(Events::onFlush, new BlogUserBlameListener(new BlogUsers(BlogUsers::ID, $this->tokenStorage)));
    }

    #[Test]
    public function aRefusedEditNeverReachesTheDatabaseThroughALaterFlush(): void
    {
        $this->login('alice');
        $tag     = new BlogTag()->setSlug('php');
        $article = new BlogArticle()->setSlug('victim')->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle('Victim'));
        $article->addTag($tag);
        $this->entityManager->persist($tag);
        $this->entityManager->persist($article);
        $this->entityManager->flush();
        $id = $article->getId();

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

        self::assertSame('victim', $this->connection->fetchOne('SELECT slug FROM content_blog_article WHERE id = ?', [$id]));
        self::assertSame('Victim', $this->connection->fetchOne('SELECT title FROM content_blog_article_translation WHERE translatable_id = ?', [$id]));
        self::assertSame('alice', $this->connection->fetchOne('SELECT updated_by_id FROM content_blog_article WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blog_article_tag WHERE blog_article_id = ?', [$id]));
    }

    private function login(string $id): void
    {
        $user = new PlainHostUser($id, 'user');
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
