<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Port;

use LogicException;
use Nowo\BlogKitBundle\Port\BlogCommentCaptchaVerifierGate;
use Nowo\BlogKitBundle\Port\BlogCommentRateLimitPolicyGate;
use Nowo\BlogKitBundle\Port\BlogCommentRequestContexts;
use Nowo\BlogKitBundle\Port\BlogKitResourcePolicyGate;
use Nowo\BlogKitBundle\Port\Ports;
use Nowo\BlogKitBundle\Security\AllowAllBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Security\BlogKitResourcePolicyInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Nowo\BlogKitBundle\Security\Captcha\NoneCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Internal\CommentCaptchaInterface;
use Nowo\BlogKitBundle\Security\Internal\CommentRateLimiterInterface;
use Nowo\BlogKitBundle\Security\Internal\ObjectAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimitPolicyInterface;
use Nowo\BlogKitBundle\Security\RateLimit\NullBlogCommentRateLimiter;
use Nowo\BlogKitBundle\ValueObject\BlogCommentCaptchaWidget;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_keys;
use function interface_exists;

/**
 * Selection rule: since 2.0 a key takes only its 1.9 interface (asked through its gate); anything else fails.
 */
final class PortsTest extends TestCase
{
    #[Test]
    public function builtInsOfTheRemoved1xInterfacesAreRefused(): void
    {
        // Since 2.0 each key takes only the 1.9 interface: an object of the bundle's internal interfaces (what a 1.x
        // port became inside the bundle) is not accepted as a host port either.
        foreach ([
            [static fn (): ObjectAccessCheckerInterface => Ports::resourceAccess(new AllowAllBlogKitResourceAccessChecker()), 'nowo_blog_kit.security.object_access.service is a ' . AllowAllBlogKitResourceAccessChecker::class . ', which does not implement ' . BlogKitResourcePolicyInterface::class],
            [static fn (): CommentRateLimiterInterface => Ports::rateLimiter(new NullBlogCommentRateLimiter()), 'nowo_blog_kit.comments.rate_limit.service is a ' . NullBlogCommentRateLimiter::class . ', which does not implement ' . BlogCommentRateLimitPolicyInterface::class],
            [static fn (): CommentCaptchaInterface => Ports::captcha(new NoneCommentCaptchaStrategy(), new RequestStack()), 'nowo_blog_kit.comments.captcha.service is a ' . NoneCommentCaptchaStrategy::class . ', which does not implement ' . BlogCommentCaptchaVerifierInterface::class],
        ] as [$build, $message]) {
            try {
                $build();
                self::fail('Refused: ' . $message);
            } catch (LogicException $exception) {
                self::assertSame($message . ' (since 2.0 the only interface this key takes; see UPGRADING.md "To 2.0.0").', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function theRemovedInterfacesAreGone(): void
    {
        foreach (Ports::REMOVED as $removed => $replacement) {
            self::assertFalse(interface_exists($removed), $removed . ' was removed in 2.0');
            self::assertTrue(interface_exists($replacement), $replacement . ' replaces it');
        }

        self::assertSame([
            'Nowo\\BlogKitBundle\\Security\\BlogKitResourceAccessCheckerInterface',
            'Nowo\\BlogKitBundle\\Security\\RateLimit\\BlogCommentRateLimiterInterface',
            'Nowo\\BlogKitBundle\\Security\\Captcha\\BlogCommentCaptchaStrategyInterface',
        ], array_keys(Ports::REMOVED));
    }

    #[Test]
    public function twinOnlyServicesAreAskedThroughTheirGate(): void
    {
        self::assertInstanceOf(BlogKitResourcePolicyGate::class, Ports::resourceAccess($this->createStub(BlogKitResourcePolicyInterface::class)));
        self::assertInstanceOf(BlogCommentRateLimitPolicyGate::class, Ports::rateLimiter($this->createStub(BlogCommentRateLimitPolicyInterface::class)));
        self::assertInstanceOf(BlogCommentCaptchaVerifierGate::class, Ports::captcha($this->createStub(BlogCommentCaptchaVerifierInterface::class), new RequestStack()));
    }

    #[Test]
    public function aResourceServiceImplementingNeitherFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_blog_kit.security.object_access.service is a stdClass');
        Ports::resourceAccess(new stdClass());
    }

    #[Test]
    public function aRateLimitServiceImplementingNeitherFails(): void
    {
        $this->expectException(LogicException::class);
        Ports::rateLimiter(new stdClass());
    }

    #[Test]
    public function aCaptchaServiceImplementingNeitherFails(): void
    {
        $this->expectException(LogicException::class);
        Ports::captcha(new stdClass(), new RequestStack());
    }

    #[Test]
    public function requestContextsCopyOnlyTopLevelStringFields(): void
    {
        $request = Request::create('https://blog.example.test/blog/x/comments', 'POST', ['a' => 'b', 'nested' => ['c' => 'd']], server: ['REMOTE_ADDR' => '10.0.0.9']);

        $context = BlogCommentRequestContexts::fromRequest($request);
        self::assertEquals(new BlogCommentRequestContext('10.0.0.9', 'blog.example.test', 'POST', '/blog/x/comments', ['a' => 'b']), $context);
    }

    #[Test]
    public function captchaGateFailsClosedWithoutARequestAndIgnoresChildForms(): void
    {
        $verifier = $this->createMock(BlogCommentCaptchaVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');
        $verifier->method('widget')->willReturn(new BlogCommentCaptchaWidget(false, 'none'));
        $gate = new BlogCommentCaptchaVerifierGate($verifier, new RequestStack());

        $factory = Forms::createFormFactory();
        $root    = $factory->createBuilder(FormType::class);
        $child   = $factory->createNamedBuilder('child', FormType::class)->add('x', TextType::class);
        $gate->configureForm($child);
        $root->add($child);
        $gate->configureForm($root);
        $form = $root->getForm();
        $form->submit(['child' => ['x' => 'y']]);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->getErrors());
        self::assertTrue($form->get('child')->isValid());
        self::assertSame(['enabled' => false, 'strategy' => 'none', 'site_key' => '', 'script_url' => '', 'widget_class' => ''], $gate->twigContext());
    }
}
