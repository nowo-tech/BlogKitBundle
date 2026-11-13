<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Port;

use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Since 1.9.0: asks a {@see BlogCommentCaptchaVerifierInterface} (the twin) where the bundle asks the 1.x port. The
 * submitted root form is rejected (`blog.comments.error_invalid`) unless the verifier accepts the request; without a
 * current request it is rejected (fail closed).
 *
 * @internal built by {@see Ports}
 */
final readonly class BlogCommentCaptchaVerifierGate implements BlogCommentCaptchaStrategyInterface
{
    public function __construct(
        private BlogCommentCaptchaVerifierInterface $verifier,
        private RequestStack $requestStack,
    ) {
    }

    public function configureForm(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmit(...));
    }

    public function twigContext(): array
    {
        return $this->verifier->widget()->toArray();
    }

    private function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        if (!$form->isRoot()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || !$this->verifier->verify(BlogCommentRequestContexts::fromRequest($request))) {
            $form->addError(new FormError('blog.comments.error_invalid'));
        }
    }
}
