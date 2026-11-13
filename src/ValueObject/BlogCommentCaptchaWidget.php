<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;

/**
 * Since 1.9.0: how the public comment form renders a captcha of a
 * {@see BlogCommentCaptchaVerifierInterface} (the same keys as the Twig context
 * of the 1.x strategies).
 */
final readonly class BlogCommentCaptchaWidget
{
    public function __construct(
        public bool $enabled,
        public string $strategy,
        public string $siteKey = '',
        public string $scriptUrl = '',
        public string $widgetClass = '',
    ) {
    }

    /**
     * @return array{enabled: bool, strategy: string, site_key: string, script_url: string, widget_class: string}
     */
    public function toArray(): array
    {
        return [
            'enabled'      => $this->enabled,
            'strategy'     => $this->strategy,
            'site_key'     => $this->siteKey,
            'script_url'   => $this->scriptUrl,
            'widget_class' => $this->widgetClass,
        ];
    }
}
