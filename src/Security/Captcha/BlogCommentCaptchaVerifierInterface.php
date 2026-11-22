<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Nowo\BlogKitBundle\ValueObject\BlogCommentCaptchaWidget;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 3): the public comment CAPTCHA without the Symfony Form builder. Configure it with
 * `comments.captcha: { strategy: service, service: <id> }`. Since 2.0 it is the only interface that key takes (the 1.x
 * twin `BlogCommentCaptchaStrategyInterface` was removed).
 *
 * The bundle renders {@see widget()} in the public comment form and, once the form is submitted, asks
 * {@see verify()} with the submitted fields; a false answer rejects the comment.
 */
interface BlogCommentCaptchaVerifierInterface
{
    public function widget(): BlogCommentCaptchaWidget;

    public function verify(BlogCommentRequestContext $request): bool;
}
