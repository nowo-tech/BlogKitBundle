<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\ImplementsCaptcha;

use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface;

/**
 * A 1.x host comment CAPTCHA strategy still on the port removed in 2.0 (W1-1): it cannot be loaded any more.
 */
final class LegacyCaptcha implements BlogCommentCaptchaStrategyInterface
{
}
