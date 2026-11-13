<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\IdMode;

use DateTimeImmutable;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use Nowo\BlogKitBundle\Doctrine\BlogUserBlameListener;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogCommentStatus;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\OwnerBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Tests\Integration\DoctrineTestCase;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\User\BlogUsers;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function sort;

/**
 * REQ-ARCH-001 rule 2 (`doctrine.user_reference: id`) on a real schema: the user fields are string columns holding the
 * id, the host user class is a plain PHP object, and every lookup by id is exact (REQ-SEC-006).
 */
final class IdModeUserReferenceTest extends DoctrineTestCase
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
    public function userFieldsAreIdColumnsWithoutRelations(): void
    {
        $article = $this->entityManager->getClassMetadata(BlogArticle::class);
        $comment = $this->entityManager->getClassMetadata(BlogComment::class);

        foreach ([[$article, 'createdBy', 'created_by_id'], [$article, 'updatedBy', 'updated_by_id'], [$comment, 'staffAuthor', 'staff_author_id'], [$comment, 'moderatedBy', 'moderated_by_id']] as [$metadata, $field, $column]) {
            self::assertFalse($metadata->hasAssociation($field));
            self::assertSame('nowo_blog_kit_user_ref', $metadata->getTypeOfField($field));
            self::assertSame($column, $metadata->getColumnName($field));
            self::assertSame(BlogUserRef::MAX_LENGTH, $metadata->getFieldMapping($field)->length);
            self::assertTrue($metadata->isNullable($field));
        }

        $this->assertTableHasColumns('content_blog_article', ['created_by_id', 'updated_by_id']);
        foreach (['content_blog_article', 'content_blog_comment'] as $table) {
            foreach ($this->connection->createSchemaManager()->listTableForeignKeys($table) as $foreignKey) {
                self::assertNotSame('blog_kit_test_user', $foreignKey->getForeignTableName(), 'no foreign key to a user table');
            }
        }
    }

    #[Test]
    public function referencesRoundTripAndBlameStampsTheCurrentUser(): void
    {
        $this->login('alice');
        $article = $this->article('by-alice');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $loaded = $this->entityManager->find(BlogArticle::class, $article->getId());
        self::assertInstanceOf(BlogArticle::class, $loaded);
        self::assertEquals(new BlogUserRef('alice'), $loaded->getCreatedBy());
        self::assertEquals(new BlogUserRef('alice'), $loaded->getUpdatedBy());

        $this->login('bob');
        $loaded->setPosition(9);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $again = $this->entityManager->find(BlogArticle::class, $article->getId());
        self::assertEquals(new BlogUserRef('alice'), $again?->getCreatedBy(), 'the author is kept');
        self::assertEquals(new BlogUserRef('bob'), $again?->getUpdatedBy());

        // Without a security user (console, Messenger) nothing is stamped.
        $this->tokenStorage->setToken(null);
        $orphan = $this->article('no-user');
        $this->entityManager->flush();
        self::assertNull($orphan->getCreatedBy());
    }

    #[Test]
    public function anExplicitAuthorIsKeptAndHostUsersAreStoredById(): void
    {
        $this->login('alice');
        $article = $this->article('explicit');
        $article->setCreatedBy(new BlogUserRef('carol'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertEquals(new BlogUserRef('carol'), $this->entityManager->find(BlogArticle::class, $article->getId())?->getCreatedBy());
    }

    #[Test]
    public function anIdThatCannotBeKeptExactlyIsRefusedBeforeTheWrite(): void
    {
        $article = $this->article('bad-id');
        $comment = new BlogComment()->setArticle($article)->setAuthorName('x')->setBody('y')
            ->setStaffAuthor(new class implements BlogUserInterface {
                public function getId(): string
                {
                    return 'bob ';
                }

                public function getUserIdentifier(): string
                {
                    return 'bob';
                }
            });
        $this->entityManager->persist($comment);

        $this->expectException(InvalidArgumentException::class);
        $this->entityManager->flush();
    }

    #[Test]
    public function ownerListingMatchesIdsByteForByte(): void
    {
        foreach (['alice' => 'a-1', 'Alice' => 'a-2', 'alicé' => 'a-3'] as $owner => $slug) {
            $this->article($slug)->setCreatedBy(new BlogUserRef($owner));
        }
        $this->article('nobody');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = new BlogArticleRepository($this->registry, LocaleTestSupport::create(), $this->users);
        $slugs      = static function (array $items): array {
            $list = array_map(static fn (BlogArticle $article): string => $article->getSlug(), $items);
            sort($list);

            return $list;
        };

        self::assertSame(['a-1', 'nobody'], $slugs($repository->findFilteredPaginated([], 1, 20, 'alice')['items']));
        self::assertSame(['a-2', 'nobody'], $slugs($repository->findFiltered([], 'Alice')));
        self::assertSame([], $repository->findFiltered([], ''));
        self::assertCount(4, $repository->findAllOrdered());

        // Owner checker on top of the repository: alice only sees and manages her rows.
        $this->login('alice');
        $roles = $this->createStub(BlogKitAccessCheckerInterface::class);
        $roles->method('canConfigure')->willReturn(false);
        $owner = new OwnerBlogKitResourceAccessChecker($roles, $this->tokenStorage);
        self::assertSame('alice', $owner->articleListingCreatedById());
        $aliceArticle = $repository->findOneBy(['slug' => 'a-1']);
        $upperArticle = $repository->findOneBy(['slug' => 'a-2']);
        self::assertInstanceOf(BlogArticle::class, $aliceArticle);
        self::assertInstanceOf(BlogArticle::class, $upperArticle);
        self::assertTrue($owner->canManageArticle($aliceArticle));
        self::assertFalse($owner->canManageArticle($upperArticle), 'Alice is not alice');

        $this->login('alice ');
        self::assertSame('', $owner->articleListingCreatedById(), 'an id that cannot be matched exactly lists nothing');
    }

    #[Test]
    public function thePhpReCheckDropsLookAlikesAFoldingCollationWouldReturn(): void
    {
        $repository = new BlogArticleRepository($this->registry, LocaleTestSupport::create(), $this->users);
        $keep       = new ReflectionMethod(BlogArticleRepository::class, 'keepOwnedBy');
        $mine       = new BlogArticle()->setSlug('mine');
        $mine->setCreatedBy(new BlogUserRef('bob'));
        $lookAlike = new BlogArticle()->setSlug('look-alike');
        $lookAlike->setCreatedBy(new BlogUserRef('Bob'));
        $nobody = new BlogArticle()->setSlug('nobody');

        self::assertSame([$mine, $nobody], $keep->invoke($repository, [$mine, $lookAlike, $nobody], 'bob'));
        self::assertSame([$mine, $lookAlike], $keep->invoke($repository, [3 => $mine, 7 => $lookAlike], null));
    }

    #[Test]
    public function commentQueriesWorkWithoutAUserRelation(): void
    {
        $article = $this->article('commented');
        $root    = new BlogComment()->setArticle($article)->setAuthorName('Reader')->setBody('Hi')->setStatus(BlogCommentStatus::Approved);
        $reply   = new BlogComment()->setArticle($article)->setAuthorName('Team')->setBody('Hello')->setStatus(BlogCommentStatus::Approved)
            ->setParent($root)->setStaffAuthor(new BlogUserRef('staff-1'))->setModeratedBy(new BlogUserRef('staff-1'))
            ->setModeratedAt(new DateTimeImmutable());
        $root->addReply($reply);
        $this->entityManager->persist($root);
        $this->entityManager->persist($reply);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = new BlogCommentRepository($this->registry, $this->users);
        $roots      = $repository->findApprovedRootCommentsByArticle($this->entityManager->find(BlogArticle::class, $article->getId()) ?? $article);
        self::assertCount(1, $roots);
        $replies = $roots[0]->getApprovedReplies();
        self::assertCount(1, $replies);
        self::assertEquals(new BlogUserRef('staff-1'), $replies[0]->getStaffAuthor());
        self::assertTrue($replies[0]->isStaffReply());
        self::assertCount(2, $repository->findFilteredPaginated([], null, 1, 20)['items']);
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
        $user = new PlainHostUser($id, $id);
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
