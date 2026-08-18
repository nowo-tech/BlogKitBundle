<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * Configures and verifies the active public-comment CAPTCHA strategy.
 */
interface BlogCommentCaptchaStrategyInterface
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
