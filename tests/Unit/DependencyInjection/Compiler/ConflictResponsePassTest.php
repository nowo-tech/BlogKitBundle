<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\BlogKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\BlogKitBundle\DependencyInjection\Configuration;
use Nowo\BlogKitBundle\EventSubscriber\BlogKitConflictSubscriber;
use Nowo\BlogKitBundle\NowoBlogKitBundle;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.10.0 (REQ-RUNTIME-003 rule 6): the conflict answer of BlogKit follows nowo-tech/transaction-doctrine's settings
 * when its bundle is registered, else 409; `nowo_blog_kit.conflict_response.status` overrides it (503 for hosts that
 * retry on 503 only).
 */
final class ConflictResponsePassTest extends TestCase
{
    public function testTheConfigurationDefaultsToTheSharedPolicyAndThePlatformAnswer(): void
    {
        $config = new Processor()->processConfiguration(new Configuration(), [[]]);
        self::assertSame(['status' => null, 'error_page' => false], $config['conflict_response']);

        $config = new Processor()->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 503, 'error_page' => true]]]);
        self::assertSame(['status' => 503, 'error_page' => true], $config['conflict_response']);

        $this->expectException(InvalidConfigurationException::class);
        new Processor()->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 500]]]);
    }

    public function testTheBundleRegistersThePass(): void
    {
        $container = new ContainerBuilder();
        new NowoBlogKitBundle()->build($container);

        $passes = 0;
        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            if ($pass instanceof ConflictResponsePass) {
                ++$passes;
            }
        }
        self::assertSame(1, $passes);
    }

    public function testWithoutTheSubscriberNothingIsWired(): void
    {
        $container = new ContainerBuilder();
        new ConflictResponsePass()->process($container);

        self::assertFalse($container->hasDefinition(ConflictResponsePass::OWN_POLICY));

        $container->setDefinition(BlogKitConflictSubscriber::class, new Definition(BlogKitConflictSubscriber::class));
        new ConflictResponsePass()->process($container);
        self::assertSame([409], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments(), 'defaults without the parameter');
    }

    public function testWithoutTransactionDoctrinesBundleBlogKitAnswers409PlainEnglish(): void
    {
        $container = $this->container(null);
        new ConflictResponsePass()->process($container);

        self::assertSame([409], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
        $subscriber = $container->getDefinition(BlogKitConflictSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $subscriber->getArgument('$policy'));
        $responder = $subscriber->getArgument('$responder');
        self::assertInstanceOf(Definition::class, $responder);
        self::assertSame(ConcurrencyConflictResponder::class, $responder->getClass());
        self::assertSame([], $responder->getArgument(2), 'no formatters');
        self::assertNull($responder->getArgument(3), 'no template without the bundle');
        self::assertNull($responder->getArgument(4), 'no translations without the bundle');
        self::assertFalse($subscriber->getArgument('$errorPage'));

        $container->compile();
        $answer = $container->get(BlogKitConflictSubscriber::class);
        self::assertInstanceOf(BlogKitConflictSubscriber::class, $answer);
    }

    public function testTheSharedResponderIsUsedWhenTheBundleAnswersConflicts(): void
    {
        $container = $this->container(null, policy: true, responder: true);
        new ConflictResponsePass()->process($container);

        $subscriber = $container->getDefinition(BlogKitConflictSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_POLICY), $subscriber->getArgument('$policy'));
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_RESPONDER), $subscriber->getArgument('$responder'));
        self::assertFalse($container->hasDefinition(ConflictResponsePass::OWN_POLICY));
    }

    public function testTheSharedPolicyWithTheBundlesTemplateWhenItsResponderIsOff(): void
    {
        $container = $this->container(null, policy: true);
        new ConflictResponsePass()->process($container);

        $subscriber = $container->getDefinition(BlogKitConflictSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_POLICY), $subscriber->getArgument('$policy'));
        $responder = $subscriber->getArgument('$responder');
        self::assertInstanceOf(Definition::class, $responder);
        self::assertInstanceOf(TaggedIteratorArgument::class, $responder->getArgument(2));
        self::assertEquals(new Reference('twig', ContainerBuilder::NULL_ON_INVALID_REFERENCE), $responder->getArgument(3));
        self::assertEquals(new Reference('translator', ContainerBuilder::NULL_ON_INVALID_REFERENCE), $responder->getArgument(4));
    }

    public function testAnExplicitStatusWinsOverTheBundle(): void
    {
        $container = $this->container(503, policy: true, responder: true, errorPage: true);
        new ConflictResponsePass()->process($container);

        self::assertSame([503], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
        $subscriber = $container->getDefinition(BlogKitConflictSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $subscriber->getArgument('$policy'));
        self::assertInstanceOf(Definition::class, $subscriber->getArgument('$responder'));
        self::assertTrue($subscriber->getArgument('$errorPage'));
    }

    private function container(?int $status, bool $policy = false, bool $responder = false, bool $errorPage = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(ConflictResponsePass::PARAMETER, ['status' => $status, 'error_page' => $errorPage]);
        $container->setDefinition(BlogKitConflictSubscriber::class, new Definition(BlogKitConflictSubscriber::class)->setPublic(true));
        if ($policy) {
            $container->setDefinition(ConflictResponsePass::SHARED_POLICY, new Definition(ConflictResponsePolicy::class));
        }
        if ($responder) {
            $container->setDefinition(ConflictResponsePass::SHARED_RESPONDER, new Definition(ConcurrencyConflictResponder::class, [new Reference(ConflictResponsePass::SHARED_POLICY)]));
        }

        return $container;
    }
}
