<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * Disables CAPTCHA (`none` strategy).
 */
final class NoneCommentCaptchaStrategy implements BlogCommentCaptchaStrategyInterface
{
    public function configureForm(FormBuilderInterface $builder): void
    {
    }

    public function twigContext(): array
    {
        return [
            'enabled'      => false,
            'strategy'     => 'none',
            'site_key'     => '',
            'script_url'   => '',
            'widget_class' => '',
        ];
    }
}
