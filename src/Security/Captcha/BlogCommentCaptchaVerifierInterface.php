<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Nowo\BlogKitBundle\ValueObject\BlogCommentCaptchaWidget;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 3): twin of {@see BlogCommentCaptchaStrategyInterface} without the Symfony Form
 * builder. Configure it exactly like the 1.x interface (`comments.captcha: { strategy: service, service: <id> }`).
 *
 * The bundle renders {@see widget()} in the public comment form and, once the form is submitted, asks
 * {@see verify()} with the submitted fields; a false answer rejects the comment.
 */
interface BlogCommentCaptchaVerifierInterface
{
    public function widget(): BlogCommentCaptchaWidget;

    public function verify(BlogCommentRequestContext $request): bool;
}
