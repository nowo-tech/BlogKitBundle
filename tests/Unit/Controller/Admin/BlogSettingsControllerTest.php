<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Controller\Admin\BlogSettingsController;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Form\BlogSettingsType;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Tests\Support\ControllerTestHelper;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class BlogSettingsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function settingsRedirectsToListing(): void
    {
        $controller = $this->createController(Request::create('/admin/blog/settings', 'GET'));

        $response = $controller->settings();

        self::assertSame('/generated/admin_blog_settings_listing', $response->headers->get('Location'));
    }

    #[Test]
    public function listingRendersFormOnGet(): void
    {
        $request  = Request::create('/admin/blog/settings/listing', 'GET');
        $settings = new BlogSettings();
        $form     = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $repository = $this->createMock(BlogSettingsRepository::class);
        $repository->expects(self::once())->method('getSingleton')->willReturn($settings);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/settings.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['page_title'] === 'admin.settings.title'
                    && $parameters['edit_section'] === BlogSettingsType::SECTION_LISTING
                    && $parameters['form'] instanceof FormView),
            )
            ->willReturn('settings');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            formFactory: $this->createSettingsFormFactory($form, BlogSettingsType::SECTION_LISTING),
            twig: $twig,
        );

        $response = $controller->listing($request);

        self::assertSame('settings', $response->getContent());
    }

    #[Test]
    public function listingFlushesResetsAndRedirectsOnValidPost(): void
    {
        $request  = Request::create('/admin/blog/settings/listing', 'POST');
        $settings = new BlogSettings();
        $form     = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $repository = $this->createMock(BlogSettingsRepository::class);
        $repository->expects(self::once())->method('getSingleton')->willReturn($settings);
        $repository->expects(self::once())->method('reset');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            entityManager: $entityManager,
            formFactory: $this->createSettingsFormFactory($form, BlogSettingsType::SECTION_LISTING),
        );

        $response = $controller->listing($request);

        self::assertSame('/generated/admin_blog_settings_listing', $response->headers->get('Location'));
        self::assertSame(['admin.flash.settings_saved'], $request->getSession()->getFlashBag()->get('success'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function sectionActionProvider(): iterable
    {
        yield 'cards' => [BlogSettingsType::SECTION_CARDS, 'cards'];
        yield 'index-aside' => [BlogSettingsType::SECTION_INDEX_ASIDE, 'indexAside'];
        yield 'article' => [BlogSettingsType::SECTION_ARTICLE, 'article'];
        yield 'comments' => [BlogSettingsType::SECTION_COMMENTS, 'comments'];
    }

    #[Test]
    #[DataProvider('sectionActionProvider')]
    public function sectionActionsRenderFormOnGet(string $section, string $method): void
    {
        $request  = Request::create('/admin/blog/settings/' . $section, 'GET');
        $settings = new BlogSettings();
        $form     = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $repository = $this->createMock(BlogSettingsRepository::class);
        $repository->expects(self::once())->method('getSingleton')->willReturn($settings);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoBlogKitBundle/admin/settings.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['edit_section'] === $section
                    && $parameters['form'] instanceof FormView),
            )
            ->willReturn('settings');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            formFactory: $this->createSettingsFormFactory($form, $section),
            twig: $twig,
        );

        $response = $controller->{$method}($request);

        self::assertSame('settings', $response->getContent());
    }

    private function createController(
        ?Request $request = null,
        ?BlogSettingsRepository $repository = null,
        ?EntityManagerInterface $entityManager = null,
        ?FormFactoryInterface $formFactory = null,
        ?Environment $twig = null,
    ): BlogSettingsController {
        $request ??= Request::create('/admin/blog/settings/listing', 'GET');

        $controller = new BlogSettingsController(
            $repository ?? $this->createMock(BlogSettingsRepository::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
        );

        ControllerTestHelper::bind($controller, $request, array_filter([
            'twig'         => $twig,
            'form.factory' => $formFactory,
        ]));

        return $controller;
    }

    private function createSettingsFormFactory(FormInterface $form, string $section): FormFactoryInterface
    {
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->expects(self::once())
            ->method('create')
            ->with(
                BlogSettingsType::class,
                self::isInstanceOf(BlogSettings::class),
                self::callback(static fn (array $options): bool => ($options[BlogSettingsType::OPTION_SECTION] ?? null) === $section),
            )
            ->willReturn($form);

        return $factory;
    }
}
