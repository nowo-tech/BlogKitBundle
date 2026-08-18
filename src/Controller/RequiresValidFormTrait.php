<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shared CSRF / form validation deny for AbstractController subclasses.
 *
 * Controllers must still call handleRequest() or submit() before this helper.
 */
trait RequiresValidFormTrait
{
    /**
     * @param FormInterface<mixed> $form
     *
     * @throws AccessDeniedHttpException
     */
    protected function requireValidForm(FormInterface $form, string $message = 'Invalid form submission.'): void
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException($message);
        }
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @throws AccessDeniedHttpException
     */
    protected function requireValidCsrfForm(FormInterface $form): void
    {
        $this->requireValidForm($form, 'Invalid CSRF token.');
    }
}
