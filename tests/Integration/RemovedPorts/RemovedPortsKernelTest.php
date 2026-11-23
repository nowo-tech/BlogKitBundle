<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\RemovedPorts;

use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\BlogKitRemovedPortsPass;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\Unrelated\OtherMissingInterfaceUser;
use Nowo\BlogKitBundle\Tests\Integration\Worker\WorkerTestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;
use function uniqid;

/**
 * W1-1 (XR-WF-M1) through the real `Bundle::build()`: every host service still tied to a port removed in 2.0
 * (deprecated since 1.9), registered by resource autodiscovery as a standard host does, fails the container build with
 * a message naming the service, the removed interface, the version that deprecated it and the replacement. Without
 * the check Symfony drops such a service without a word (a class that cannot be loaded keeps only a loading error and
 * is removed as unused; an `IGNORE` decorator of a missing id is removed): a host decorator that RESTRICTED the
 * object access checker would silently stop running, which widens access to the blog administration.
 */
#[CoversClass(BlogKitRemovedPortsPass::class)]
final class RemovedPortsKernelTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->directory, sys_get_temp_dir() . '/nowo-blog-kit-worker']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedHosts(): iterable
    {
        yield 'class implementing only the removed object access interface' => ['ImplementsAccess', 'The service "Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\ImplementsAccess\LegacyOwnerChecker" cannot be loaded: Class "Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface" not found', BlogKitResourcePolicyInterface::class];
        yield 'class implementing only the removed rate limiter interface' => ['ImplementsRateLimit', 'The service "Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\ImplementsRateLimit\LegacyRateLimiter" cannot be loaded: Class "Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface" not found', BlogCommentRateLimitPolicyInterface::class];
        yield 'class implementing only the removed captcha interface' => ['ImplementsCaptcha', 'The service "Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\ImplementsCaptcha\LegacyCaptcha" cannot be loaded: Class "Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface" not found', BlogCommentCaptchaVerifierInterface::class];
        yield '#[AsAlias] of the removed id' => ['AsAlias', 'The service id "Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface" (an alias of "Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\AsAlias\HostAccessChecker") is no longer read', BlogKitResourcePolicyInterface::class];
        yield '#[AsDecorator] of the removed id (a restricting decorator)' => ['AsDecorator', 'The service "Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\AsDecorator\RestrictingAccessDecorator" decorates "Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface", which no longer exists', BlogKitResourcePolicyInterface::class];
        yield '#[AsDecorator] of the removed id with onInvalid: IGNORE' => ['AsDecoratorIgnore', 'The service "Nowo\BlogKitBundle\Tests\Fixtures\RemovedPorts\AsDecoratorIgnore\OptionalAccessDecorator" decorates "Nowo\BlogKitBundle\Security\BlogKitResourceAccessCheckerInterface", which no longer exists', BlogKitResourcePolicyInterface::class];
    }

    #[DataProvider('refusedHosts')]
    #[Test]
    public function aHostServiceOnARemovedPortFailsTheBuild(string $variant, string $message, string $replacement): void
    {
        $kernel = $this->kernel($variant);

        try {
            $kernel->boot();
            self::fail('The container build must fail.');
        } catch (LogicException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertStringContainsString('(deprecated since 1.9) was removed in 2.0', $exception->getMessage());
            self::assertStringContainsString('implement "' . $replacement . '"', $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * Only the exact class name of a removed interface matches: an unused class implementing another missing interface
     * (whose name merely starts like it) keeps its lazy loading error and the container compiles.
     */
    #[Test]
    public function anUnrelatedMissingInterfaceStillCompiles(): void
    {
        $kernel = $this->kernel('Unrelated');

        try {
            $kernel->boot();
            self::assertFalse($kernel->getContainer()->has(OtherMissingInterfaceUser::class));
            self::assertSame('test', $kernel->getContainer()->getParameter('kernel.environment'));
        } finally {
            $kernel->shutdown();
        }
    }

    private function kernel(string $variant): WorkerTestKernel
    {
        $this->directory = sys_get_temp_dir() . '/nowo-blog-kit-removed-ports-' . uniqid('', true);
        new Filesystem()->mkdir($this->directory);

        return new WorkerTestKernel(WorkerTestKernel::STANDARD, $this->directory, null, $variant);
    }
}
