<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\BlogKitBundle\Controller\Admin\BlogSettingsController;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Nowo\BlogKitBundle\Tests\Support\ControllerTestHelper;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
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
    public function settingsRendersFormOnGet(): void
    {
        $request  = Request::create('/admin/blog/settings', 'GET');
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
                    && $parameters['form'] instanceof FormView),
            )
            ->willReturn('settings');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            formFactory: $this->createSettingsFormFactory($form),
            twig: $twig,
        );

        $response = $controller->settings($request);

        self::assertSame('settings', $response->getContent());
    }

    #[Test]
    public function settingsFlushesResetsAndRedirectsOnValidPost(): void
    {
        $request  = Request::create('/admin/blog/settings', 'POST');
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
            formFactory: $this->createSettingsFormFactory($form),
        );

        $response = $controller->settings($request);

        self::assertSame('/generated/admin_blog_settings', $response->headers->get('Location'));
        self::assertSame(['admin.flash.settings_saved'], $request->getSession()->getFlashBag()->get('success'));
    }

    private function createController(
        ?Request $request = null,
        ?BlogSettingsRepository $repository = null,
        ?EntityManagerInterface $entityManager = null,
        ?FormFactoryInterface $formFactory = null,
        ?Environment $twig = null,
    ): BlogSettingsController {
        $request ??= Request::create('/admin/blog/settings', 'GET');

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

    private function createSettingsFormFactory(FormInterface $form): FormFactoryInterface
    {
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturn($form);

        return $factory;
    }
}
