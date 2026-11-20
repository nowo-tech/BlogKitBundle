<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Tests\Integration\Worker\Entity\HostNote;
use Nowo\BlogKitBundle\Tests\Support\HostRef\HostArticleLink;
use Nowo\BlogKitBundle\Tests\Support\HostRef\HostChild;
use Nowo\BlogKitBundle\Tests\Support\HostRef\HostParent;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\User\BlogUsers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function str_repeat;

/**
 * Audits XD-3 / XD2-1 (ported from the auditor's re-check, MySQL, MariaDB, PostgreSQL): a blog flush refused by
 * {@see BlogUserBlameListener} on your (host) manager writes nothing of the blog, never closes, clears or resets your
 * manager, keeps every pending change of yours for your next flush, and leaves the manager usable: an untouched
 * translation and an entity of yours still point at a managed article.
 */
#[Group('database')]
final class RefusedFlushHostManagerTest extends DatabaseTestCase
{
    private TokenStorage $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenStorage();
        $this->entityManager->getEventManager()->addEventListener(Events::onFlush, new BlogUserBlameListener(new BlogUsers(BlogUsers::ID, $this->tokens), $this->registry));
    }

    #[Test]
    public function yourChangesSurviveAndNothingOfTheRefusedWriteIsPersisted(): void
    {
        $manager = $this->entityManager;
        $this->login('alice');
        $victim = $this->article('victim');
        $other  = $this->article('other');
        $tag    = new BlogTag()->setSlug('php');
        $victim->addTag($tag);
        $parent = new HostParent()->addChild()->addChild();
        $doomed = new HostNote('doomed');
        foreach ([$victim, $other, $tag, $parent, $doomed] as $entity) {
            $manager->persist($entity);
        }

        $manager->flush();

        // Your pending work of every kind.
        $parent->name = 'host-dirty';
        $parent->children->clear();
        $manager->remove($doomed);
        $manager->persist(new HostNote('host pending'));
        $link = new HostArticleLink($other); // an untouched, managed article
        $manager->persist($link);

        // The refused blog work.
        $this->login(str_repeat('x', 129));
        $victim->setSlug('defaced');
        $english = $victim->getTranslation('en');
        self::assertNotNull($english);
        $victim->getTranslations()->removeElement($english);
        $victim->setTags([]);
        $manager->persist($this->article('intruder'));

        try {
            $manager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        self::assertTrue($manager->isOpen());
        self::assertSame(0, $this->registry->resets);

        $this->login('bob');
        $manager->flush();

        $db = $this->writer;
        self::assertSame('host-dirty', $db->fetchOne('SELECT name FROM host_ref_parent WHERE id = ?', [$parent->id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM host_ref_child'));
        self::assertSame(['host pending'], $db->fetchFirstColumn('SELECT text FROM worker_host_note'));
        self::assertSame((int) $other->getId(), (int) $db->fetchOne('SELECT article_id FROM host_ref_article_link'));
        self::assertSame('victim', $db->fetchOne('SELECT slug FROM content_blog_article WHERE id = ?', [$victim->getId()]));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM content_blog_article_translation WHERE translatable_id = ?', [$victim->getId()]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM content_blog_article_tag'));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM content_blog_article WHERE slug = 'intruder'"));
        self::assertSame('alice', $db->fetchOne('SELECT updated_by_id FROM content_blog_article WHERE id = ?', [$other->getId()]));
        self::assertSame('alice', $db->fetchOne('SELECT updated_by_id FROM content_blog_article WHERE id = ?', [$victim->getId()]));
    }

    /** Only a field of the article is refused: its untouched translations and your entity pointing at it stay valid. */
    #[Test]
    public function yourEntityPointingAtTheRefusedArticleStillFlushes(): void
    {
        $manager = $this->entityManager;
        $this->login('alice');
        $victim = $this->article('victim');
        $manager->persist($victim);
        $manager->flush();
        $link = new HostArticleLink($victim);
        $manager->persist($link);

        $this->login(str_repeat('x', 129));
        $victim->setSlug('defaced');
        try {
            $manager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        $this->login('bob');
        $manager->flush();
        $manager->persist(new HostNote('later'));
        $manager->flush();

        self::assertSame('victim', $this->writer->fetchOne('SELECT slug FROM content_blog_article'));
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT COUNT(*) FROM content_blog_article'));
        self::assertSame((int) $victim->getId(), (int) $this->writer->fetchOne('SELECT article_id FROM host_ref_article_link'));
        self::assertSame(['later'], $this->writer->fetchFirstColumn('SELECT text FROM worker_host_note'));
        self::assertSame(0, $this->registry->resets);
    }

    protected function tearDown(): void
    {
        // The host tables reference the blog tables: dropped here, so the next test can drop and recreate its schema.
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            new SchemaTool($this->entityManager)->dropSchema(array_map($this->entityManager->getClassMetadata(...), [HostArticleLink::class, HostChild::class, HostParent::class, HostNote::class]));
        }

        parent::tearDown();
    }

    protected function extraEntityPaths(): array
    {
        return [__DIR__ . '/../Integration/Worker/Entity', __DIR__ . '/../Support/HostRef'];
    }

    private function login(string $id): void
    {
        $user = new PlainHostUser($id, 'user');
        $this->tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function article(string $slug): BlogArticle
    {
        $article = new BlogArticle()->setSlug($slug)->setPublished(true)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle($slug));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('en')->setTitle($slug . ' en'));

        return $article;
    }
}
