<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Port;

use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitPortsPass;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitRemovedPortsPass;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Nowo\BlogKitBundle\Security\Internal\CommentCaptchaInterface;
use Nowo\BlogKitBundle\Security\Internal\CommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\Internal\ObjectAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

use function get_debug_type;
use function sprintf;

/**
 * Since 1.9.0: the host ports behind `security.object_access.service`, `comments.rate_limit.service` and
 * `comments.captcha.service`, asked through their gates.
 *
 * Since 2.0 each key takes only the 1.9 interface (`BlogKitResourcePolicyInterface`,
 * `BlogCommentRateLimitPolicyInterface`, `BlogCommentCaptchaVerifierInterface`): the 1.x interfaces were removed. A
 * service that implements anything else fails when it is built (the compiler pass {@see BlogKitPortsPass} already
 * stops the build when it knows the class, and {@see BlogKitRemovedPortsPass}
 * when a class still implements a removed interface).
 *
 * @internal service factories
 */
final class Ports
{
    /** The 1.x port interfaces removed in 2.0 (deprecated since 1.9) => the interface that replaces each one. */
    public const array REMOVED = [
        'Nowo\\BlogKitBundle\\Security\\BlogKitResourceAccessCheckerInterface'        => BlogKitResourcePolicyInterface::class,
        'Nowo\\BlogKitBundle\\Security\\RateLimit\\BlogCommentRateLimiterInterface'   => BlogCommentRateLimitPolicyInterface::class,
        'Nowo\\BlogKitBundle\\Security\\Captcha\\BlogCommentCaptchaStrategyInterface' => BlogCommentCaptchaVerifierInterface::class,
    ];

    public static function resourceAccess(object $service): ObjectAccessCheckerInterface
    {
        if (!$service instanceof BlogKitResourcePolicyInterface) {
            throw self::wrongType('security.object_access.service', $service, BlogKitResourcePolicyInterface::class);
        }

        return new BlogKitResourcePolicyGate($service);
    }

    public static function rateLimiter(object $service): CommentRateLimiterInterface
    {
        if (!$service instanceof BlogCommentRateLimitPolicyInterface) {
            throw self::wrongType('comments.rate_limit.service', $service, BlogCommentRateLimitPolicyInterface::class);
        }

        return new BlogCommentRateLimitPolicyGate($service);
    }

    public static function captcha(object $service, RequestStack $requestStack): CommentCaptchaInterface
    {
        if (!$service instanceof BlogCommentCaptchaVerifierInterface) {
            throw self::wrongType('comments.captcha.service', $service, BlogCommentCaptchaVerifierInterface::class);
        }

        return new BlogCommentCaptchaVerifierGate($service, $requestStack);
    }

    private static function wrongType(string $key, object $service, string $interface): LogicException
    {
        return new LogicException(sprintf('nowo_blog_kit.%s is a %s, which does not implement %s (since 2.0 the only interface this key takes; see UPGRADING.md "To 2.0.0").', $key, get_debug_type($service), $interface));
    }
}
