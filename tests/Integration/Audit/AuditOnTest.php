<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Audit;

use DateTimeImmutable;
use Doctrine\ORM\Events;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Port\BlogKitResourcePolicyGate;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\OwnerBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Support\BlogUserIdResolver;
use Nowo\BlogKitBundle\Tests\Integration\DoctrineTestCase;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\Tests\Support\Twin\OwnerResourcePolicy;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogArticleSnapshot;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Nowo\BlogKitBundle\ValueObject\UnreadableBlogUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function str_repeat;

/**
 * Audit ON-M1 / ON-I1 (regression tests built from the auditor's PoC): in the id mode an owner whose stored id cannot be
 * read, or a creator whose id cannot be kept, never turns an article into an unowned one any manager could edit.
 */
final class AuditOnTest extends DoctrineTestCase
{
    private TokenStorage $tokenStorage;

    private BlogUsers $users;

    protected function userReference(): string
    {
        return BlogUsers::ID;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenStorage = new TokenStorage();
        $this->users        = new BlogUsers(BlogUsers::ID, $this->tokenStorage);
        $this->entityManager->getEventManager()->addEventListener(Events::onFlush, new BlogUserBlameListener($this->users));
    }

    #[Test]
    public function aStoredInvalidOwnerIsNobodysToManage(): void
    {
        $this->login('alice');
        $article = $this->article('owned');
        $this->entityManager->flush();
        $id = $article->getId();
        // A legacy / hand-written value the type cannot keep exactly.
        $this->connection->executeStatement("UPDATE content_blog_article SET created_by_id = 'alice ' WHERE id = ?", [$id]);
        $this->entityManager->clear();

        $loaded = $this->entityManager->find(BlogArticle::class, $id);
        self::assertInstanceOf(BlogArticle::class, $loaded);
        $owner = $loaded->getCreatedBy();
        self::assertEquals(new UnreadableBlogUserRef('alice '), $owner);
        self::assertSame('', $owner?->getId());
        self::assertSame('', $owner?->getUserIdentifier());
        self::assertNull(BlogUserRef::idOf($owner));
        self::assertFalse(BlogUserIdResolver::isSame($owner, $owner));
        self::assertFalse(new BlogUserRef('alice')->equals($owner));

        foreach (['mallory', 'alice'] as $user) {
            $this->login($user);
            self::assertFalse($this->owner()->canManageArticle($loaded), $user . ' cannot manage an article whose owner is unreadable');
            self::assertFalse(new BlogKitResourcePolicyGate(new OwnerResourcePolicy($this->roles(), $this->tokenStorage))->canManageArticle($loaded), 'twin policy: ' . $user);
        }

        $snapshot = BlogArticleSnapshot::of($loaded);
        self::assertTrue($snapshot->createdByUnreadable);
        self::assertNull($snapshot->createdById);

        $repository = new BlogArticleRepository($this->registry, LocaleTestSupport::create(), $this->users);
        self::assertSame([], array_map(static fn (BlogArticle $a): string => $a->getSlug(), $repository->findFiltered([], 'mallory')), 'not listed as unowned');
        self::assertSame([], $repository->findFilteredPaginated([], 1, 20, 'alice')['items']);

        // A configure-role edit keeps the stored value exactly (never rewritten as NULL).
        $this->login('admin');
        $loaded->setPosition(4);
        $this->entityManager->flush();
        self::assertSame('alice ', $this->connection->fetchOne('SELECT created_by_id FROM content_blog_article WHERE id = ?', [$id]));
        self::assertSame('admin', $this->connection->fetchOne('SELECT updated_by_id FROM content_blog_article WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'longer than 128' => [str_repeat('x', 129)];
        yield 'trailing space' => ['bob '];
        yield 'leading nbsp' => ["\u{00A0}bob"];
        yield 'zero-width space' => ["b\u{200B}ob"];
    }

    #[Test]
    #[DataProvider('refusedIds')]
    public function aCreatorWhoseIdIsRefusedCannotSaveAnUnownedArticle(string $id): void
    {
        $this->login($id);
        $this->article('refused');

        try {
            $this->entityManager->flush();
            self::fail('The flush must be refused.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM content_blog_article WHERE slug = 'refused'"));
        $this->login($id);
        self::assertSame('', $this->owner()->articleListingCreatedById(), 'an id that cannot be matched lists nothing');
    }

    #[Test]
    public function theListingGateAppliesInTheIdModeOnly(): void
    {
        $long = str_repeat('x', 129);
        $this->login($long);

        self::assertSame($long, new OwnerBlogKitResourceAccessChecker($this->roles(), $this->tokenStorage)->articleListingCreatedById(), 'entity mode (no BlogUsers): as in 1.8');
        self::assertSame($long, new OwnerBlogKitResourceAccessChecker($this->roles(), $this->tokenStorage, new BlogUsers(BlogUsers::ENTITY))->articleListingCreatedById());
        self::assertSame('', $this->owner()->articleListingCreatedById());
    }

    private function owner(): OwnerBlogKitResourceAccessChecker
    {
        return new OwnerBlogKitResourceAccessChecker($this->roles(), $this->tokenStorage, $this->users);
    }

    private function roles(): BlogKitAccessCheckerInterface
    {
        $roles = $this->createStub(BlogKitAccessCheckerInterface::class);
        $roles->method('canConfigure')->willReturn(false);

        return $roles;
    }

    private function article(string $slug): BlogArticle
    {
        $article = new BlogArticle()->setSlug($slug)->setPublishedAt(new DateTimeImmutable('2026-01-01'));
        $article->addTranslation(new BlogArticleTranslation()->setLocale('es')->setTitle($slug));
        $this->entityManager->persist($article);

        return $article;
    }

    private function login(string $id): void
    {
        $user = new PlainHostUser($id, $id !== '' ? $id : 'empty');
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
