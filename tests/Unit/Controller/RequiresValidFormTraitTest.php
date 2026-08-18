<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller;

use Nowo\BlogKitBundle\Controller\RequiresValidFormTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RequiresValidFormTraitTest extends TestCase
{
    #[Test]
    public function requireValidFormAllowsValidSubmittedForm(): void
    {
        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => true,
            'createView'  => new FormView(),
        ]);

        $this->createTraitHarness()->assertValidForm($form);

        self::assertTrue(true);
    }

    #[Test]
    public function requireValidFormThrowsWhenFormWasNotSubmitted(): void
    {
        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Invalid form submission.');

        $this->createTraitHarness()->assertValidForm($form);
    }

    #[Test]
    public function requireValidFormThrowsWhenFormIsInvalid(): void
    {
        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Invalid form submission.');

        $this->createTraitHarness()->assertValidForm($form);
    }

    #[Test]
    public function requireValidCsrfFormUsesInvalidTokenMessage(): void
    {
        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => true,
            'isValid'     => false,
            'createView'  => new FormView(),
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $this->createTraitHarness()->assertValidCsrfForm($form);
    }

    private function createTraitHarness(): object
    {
        return new class {
            use RequiresValidFormTrait;

            public function assertValidForm(FormInterface $form): void
            {
                $this->requireValidForm($form);
            }

            public function assertValidCsrfForm(FormInterface $form): void
            {
                $this->requireValidCsrfForm($form);
            }

            protected function createAccessDeniedException(string $message = 'Access Denied.'): AccessDeniedHttpException
            {
                return new AccessDeniedHttpException($message);
            }
        };
    }
}
