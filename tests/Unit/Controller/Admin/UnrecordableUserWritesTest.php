<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Controller\Admin\BlogArticleController;
use Nowo\BlogKitBundle\Controller\Admin\BlogSettingsController;
use Nowo\BlogKitBundle\Controller\Admin\BlogTagController;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitAccessDenied;
use Nowo\BlogKitBundle\Tests\Support\ControllerTestHelper;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\BlogKitBundle\Tests\Support\PageFixtures;
use Nowo\BlogKitBundle\Tests\Support\PlainHostUser;
use Nowo\BlogKitBundle\User\BlogUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function str_repeat;

/**
 * Audit ON-R1: with `user_reference: id`, a signed-in user whose id cannot be kept exactly is refused (403) at the top
 * of every route that writes articles, tags or settings, before any form touches an entity, so nothing stays pending
 * on the entity manager.
 */
final class UnrecordableUserWritesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function writeRoutes(): iterable
    {
        foreach (['article.new', 'article.edit', 'article.editModal', 'article.inlineUpdate', 'article.delete', 'tag.new', 'tag.edit', 'tag.delete', 'settings.listing'] as $route) {
            yield $route => [$route];
        }
    }

    #[Test]
    #[DataProvider('writeRoutes')]
    public function refusedBeforeTheFormTouchesAnything(string $route): void
    {
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects(self::never())->method(self::anything());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method(self::anything());
        $users   = $this->users(str_repeat('x', 129));
        $request = Request::create('/admin/blog', 'POST');
        $denied  = new BlogKitAccessDenied(new AllowAllBlogKitResourceAccessChecker());

        $article             = new BlogArticle()->setId(5)->setSlug('a');
        $tag                 = new BlogTag()->setId(6)->setSlug('t');
        [$controller, $call] = match ($route) {
            'article.new', 'article.edit', 'article.editModal', 'article.inlineUpdate', 'article.delete' => [
                new BlogArticleController($this->createStub(BlogArticleRepository::class), $entityManager, ControllerTestHelper::csrfOnlyFormFactory($formFactory), ControllerTestHelper::filterFormFactory($formFactory), LocaleTestSupport::create(), $denied, 20, PageFixtures::factory(), $users),
                match ($route) {
                    'article.new'          => static fn (BlogArticleController $c): Response => $c->new($request),
                    'article.edit'         => static fn (BlogArticleController $c): Response => $c->edit($article, $request),
                    'article.editModal'    => static fn (BlogArticleController $c): Response => $c->editModal($article, $request),
                    'article.inlineUpdate' => static fn (BlogArticleController $c): Response => $c->inlineUpdate($article, $request),
                    default                => static fn (BlogArticleController $c): RedirectResponse => $c->delete($article, $request),
                },
            ],
            'tag.new', 'tag.edit', 'tag.delete' => [
                new BlogTagController($this->createStub(BlogTagRepository::class), $entityManager, ControllerTestHelper::csrfOnlyFormFactory($formFactory), ControllerTestHelper::filterFormFactory($formFactory), LocaleTestSupport::create(), $denied, PageFixtures::factory(), $users),
                match ($route) {
                    'tag.new'  => static fn (BlogTagController $c): Response => $c->new($request),
                    'tag.edit' => static fn (BlogTagController $c): Response => $c->edit($tag, $request),
                    default    => static fn (BlogTagController $c): RedirectResponse => $c->delete($tag, $request),
                },
            ],
            default => [
                new BlogSettingsController($this->createStub(BlogSettingsRepository::class), $entityManager, PageFixtures::factory(), $users),
                static fn (BlogSettingsController $c): Response => $c->listing($request),
            ],
        };
        ControllerTestHelper::bind($controller, $request, ['form.factory' => $formFactory]);

        $this->expectException(AccessDeniedException::class);
        $call($controller);
    }

    #[Test]
    public function onlyTheIdModeWithASignedInUnrecordableUserIsRefused(): void
    {
        self::assertTrue($this->users(str_repeat('x', 129))->isUnrecordable());
        self::assertTrue($this->users('bob ')->isUnrecordable());
        self::assertFalse($this->users('bob')->isUnrecordable());
        self::assertFalse(new BlogUsers(BlogUsers::ID, new TokenStorage())->isUnrecordable(), 'no signed-in user');
        self::assertFalse(new BlogUsers(BlogUsers::ENTITY, $this->storage(str_repeat('x', 129)))->isUnrecordable(), 'entity mode');
    }

    private function users(string $id): BlogUsers
    {
        return new BlogUsers(BlogUsers::ID, $this->storage($id));
    }

    private function storage(string $id): TokenStorage
    {
        $user    = new PlainHostUser($id, 'user');
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $storage;
    }
}
