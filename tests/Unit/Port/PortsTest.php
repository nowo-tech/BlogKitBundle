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

/**
 * Selection rule (1.9.0): the 1.x interface is asked as before, a twin-only service through its gate, anything else fails.
 */
final class PortsTest extends TestCase
{
    #[Test]
    public function legacyServicesAreAskedAsBefore(): void
    {
        $access  = new AllowAllBlogKitResourceAccessChecker();
        $limiter = new NullBlogCommentRateLimiter();
        $captcha = new NoneCommentCaptchaStrategy();

        self::assertSame($access, Ports::resourceAccess($access));
        self::assertSame($limiter, Ports::rateLimiter($limiter));
        self::assertSame($captcha, Ports::captcha($captcha, new RequestStack()));
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
