<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Port;

use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitPortsPass;
use Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

use function get_debug_type;
use function sprintf;
use function trigger_deprecation;

/**
 * Since 1.9.0: the selection rule of the host ports. The same configuration key takes the 1.x interface or its twin:
 * a service that implements the 1.x interface is asked exactly as in 1.8 (also when it implements the twin too); one
 * that implements only the twin is asked through its gate; anything else fails when it is built (the compiler pass
 * {@see BlogKitPortsPass} already stops the build when it knows the
 * class).
 *
 * Since 1.12.0 a service that implements only the 1.x interface triggers a deprecation notice when it is built.
 *
 * @internal service factories
 */
final class Ports
{
    public static function resourceAccess(object $service): BlogKitResourceAccessCheckerInterface
    {
        return match (true) {
            $service instanceof BlogKitResourceAccessCheckerInterface => self::legacy($service, 'security.object_access.service', BlogKitResourceAccessCheckerInterface::class, BlogKitResourcePolicyInterface::class),
            $service instanceof BlogKitResourcePolicyInterface        => new BlogKitResourcePolicyGate($service),
            default                                                   => throw self::neither('security.object_access.service', $service, BlogKitResourceAccessCheckerInterface::class, BlogKitResourcePolicyInterface::class),
        };
    }

    public static function rateLimiter(object $service): BlogCommentRateLimiterInterface
    {
        return match (true) {
            $service instanceof BlogCommentRateLimiterInterface     => self::legacy($service, 'comments.rate_limit.service', BlogCommentRateLimiterInterface::class, BlogCommentRateLimitPolicyInterface::class),
            $service instanceof BlogCommentRateLimitPolicyInterface => new BlogCommentRateLimitPolicyGate($service),
            default                                                 => throw self::neither('comments.rate_limit.service', $service, BlogCommentRateLimiterInterface::class, BlogCommentRateLimitPolicyInterface::class),
        };
    }

    public static function captcha(object $service, RequestStack $requestStack): BlogCommentCaptchaStrategyInterface
    {
        return match (true) {
            $service instanceof BlogCommentCaptchaStrategyInterface => self::legacy($service, 'comments.captcha.service', BlogCommentCaptchaStrategyInterface::class, BlogCommentCaptchaVerifierInterface::class),
            $service instanceof BlogCommentCaptchaVerifierInterface => new BlogCommentCaptchaVerifierGate($service, $requestStack),
            default                                                 => throw self::neither('comments.captcha.service', $service, BlogCommentCaptchaStrategyInterface::class, BlogCommentCaptchaVerifierInterface::class),
        };
    }

    /**
     * Since 1.12.0: a host port that implements only the 1.x interface keeps working, with a deprecation notice when the
     * service is built (once per container, i.e. per request or per worker): 2.0 accepts only the twin.
     *
     * @template T of object
     *
     * @param T $service
     *
     * @return T
     */
    private static function legacy(object $service, string $key, string $legacy, string $twin): object
    {
        if (!$service instanceof $twin) {
            trigger_deprecation('nowo-tech/blog-kit-bundle', '1.12.0', sprintf('The service "%s" of "nowo_blog_kit.%s" implements the deprecated %s, removed in 2.0. Implement %s instead (the same configuration key takes it now; see UPGRADING.md "To 1.9.0").', get_debug_type($service), $key, $legacy, $twin));
        }

        return $service;
    }

    private static function neither(string $key, object $service, string $legacy, string $twin): LogicException
    {
        return new LogicException(sprintf('nowo_blog_kit.%s is a %s, which implements neither %s nor %s.', $key, get_debug_type($service), $twin, $legacy));
    }
}
