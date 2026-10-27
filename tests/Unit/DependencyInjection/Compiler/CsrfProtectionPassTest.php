<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\BlogKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(CsrfProtectionPass::class)]
final class CsrfProtectionPassTest extends TestCase
{
    public function testPassesWhenFormCsrfAndTheTokenManagerAreAvailable(): void
    {
        $container = $this->container();
        $container->setParameter('form.type_extension.csrf.enabled', true);

        new CsrfProtectionPass()->process($container);

        $this->addToAssertionCount(1);
    }

    public function testSkipsWhenTheBundleIsNotConfigured(): void
    {
        new CsrfProtectionPass()->process(new ContainerBuilder());

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function failingProvider(): iterable
    {
        yield 'no token manager' => [false, true, true];
        yield 'no form CSRF extension' => [true, false, true];
        yield 'form CSRF disabled' => [true, true, false];
    }

    #[DataProvider('failingProvider')]
    public function testFailsClosedWithoutCsrf(bool $tokenManager, bool $extension, bool $enabled): void
    {
        $container = $this->container($tokenManager, $extension);
        $container->setParameter('form.type_extension.csrf.enabled', $enabled);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('require CSRF protection');

        new CsrfProtectionPass()->process($container);
    }

    private function container(bool $tokenManager = true, bool $extension = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(CsrfProtectionPass::BUNDLE_PARAMETER, []);
        if ($tokenManager) {
            $container->setDefinition('security.csrf.token_manager', new Definition(stdClass::class));
        }
        if ($extension) {
            $container->setDefinition('form.type_extension.csrf', new Definition(stdClass::class));
        }

        return $container;
    }
}
