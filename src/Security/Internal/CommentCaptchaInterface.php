<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Internal;

use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Since 2.0: how the public comment form configures and verifies the active CAPTCHA strategy. Implemented by the
 * built-in strategies and by the gate that asks your {@see BlogCommentCaptchaVerifierInterface}
 * (`comments.captcha.strategy: service`).
 *
 * @internal not an extension point: implement {@see BlogCommentCaptchaVerifierInterface}. It replaces, inside the
 *           bundle, the 1.x `Security\Captcha\BlogCommentCaptchaStrategyInterface` removed in 2.0
 */
interface CommentCaptchaInterface
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    public function configureForm(FormBuilderInterface $builder): void;

    /**
     * @return array{enabled: bool, strategy: string, site_key: string, script_url: string, widget_class: string}
     */
    public function twigContext(): array;
}
