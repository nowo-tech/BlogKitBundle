<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Port;

use DateTimeImmutable;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogComment;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Port\BlogCommentCaptchaVerifierGate;
use Nowo\BlogKitBundle\Port\BlogCommentRateLimitPolicyGate;
use Nowo\BlogKitBundle\Port\BlogKitResourcePolicyGate;
use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;
use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaStrategyInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Security\RateLimit\BlogCommentRateLimiterInterface;
use Nowo\BlogKitBundle\Tests\Support\Legacy183\LegacyCacheBlogCommentRateLimiter;
use Nowo\BlogKitBundle\Tests\Support\Legacy183\LegacyOwnerBlogKitResourceAccessChecker;
use Nowo\BlogKitBundle\Tests\Support\Legacy183\LegacyRemoteCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Tests\Support\TestUser;
use Nowo\BlogKitBundle\Tests\Support\Twin\FixedWindowRateLimitPolicy;
use Nowo\BlogKitBundle\Tests\Support\Twin\OwnerResourcePolicy;
use Nowo\BlogKitBundle\Tests\Support\Twin\TurnstileVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * REQ-ARCH-001 (1.9.0): a host port written against the twin and asked through its gate answers exactly like the same
 * policy written against the 1.x interface, here the literal 1.8.3 classes ({@see LegacyOwnerBlogKitResourceAccessChecker},
 * {@see LegacyCacheBlogCommentRateLimiter}, {@see LegacyRemoteCommentCaptchaStrategy}).
 */
final class PortTwinsEquivalenceTest extends TestCase
{
    #[Test]
    public function ownerPolicyThroughTheGateDecidesLikeThe183Checker(): void
    {
        $alice   = new TestUser()->setId(1)->setEmail('alice@example.test');
        $bob     = new TestUser()->setId(2)->setEmail('bob@example.test');
        $tag     = new BlogTag()->setSlug('t');
        $comment = new BlogComment()->setArticle(new BlogArticle()->setSlug('c'));

        foreach ([true, false] as $configure) {
            $roles = $this->createStub(BlogKitAccessCheckerInterface::class);
            $roles->method('canConfigure')->willReturn($configure);
            foreach ([$alice, $bob, null] as $current) {
                $storage = new TokenStorage();
                if ($current instanceof TestUser) {
                    $storage->setToken(new UsernamePasswordToken($current, 'main', ['ROLE_ADMIN']));
                }

                $legacy = new LegacyOwnerBlogKitResourceAccessChecker($roles, $storage);
                $twin   = new BlogKitResourcePolicyGate(new OwnerResourcePolicy($roles, $storage));
                self::assertSame($legacy->articleListingCreatedById(), $twin->articleListingCreatedById());
                self::assertSame($legacy->canManageTag($tag), $twin->canManageTag($tag));
                self::assertSame($legacy->canModerateComment($comment), $twin->canModerateComment($comment));
                foreach ([$alice, $bob, null] as $owner) {
                    $article = new BlogArticle()->setId(7)->setSlug('a');
                    $article->setCreatedBy($owner);
                    self::assertSame($legacy->canManageArticle($article), $twin->canManageArticle($article));
                }
            }
        }
    }

    #[Test]
    public function rateLimitPolicyThroughTheGateDecidesLikeThe183Limiter(): void
    {
        $clock   = new MockClock(new DateTimeImmutable('2026-01-01 00:00:00'));
        $legacy  = new LegacyCacheBlogCommentRateLimiter(new ArrayAdapter(), $clock, 3, 60, CommentRateLimitStrategy::PerIpArticle);
        $twin    = new BlogCommentRateLimitPolicyGate(new FixedWindowRateLimitPolicy(new ArrayAdapter(), $clock, 3, 60));
        $article = new BlogArticle()->setSlug('post');
        $other   = new BlogArticle()->setSlug('other');

        $denied = 0;
        for ($i = 0; $i < 16; ++$i) {
            $ip      = $i % 3 === 2 ? ['2001:db8::1', '2001:db8::2'][$i % 2] : '10.0.0.1';
            $target  = $i % 5 === 4 ? $other : $article;
            $request = Request::create('/blog/post/comments', 'POST', server: ['REMOTE_ADDR' => $ip]);
            $outcome = $this->outcome($legacy, $request, $target);
            self::assertSame($outcome, $this->outcome($twin, $request, $target), 'submission ' . $i);
            $denied += $outcome === 'allowed' ? 0 : 1;
            if ($i === 10) {
                $clock->sleep(61);
            }
        }

        self::assertGreaterThan(0, $denied, 'the matrix reaches the limit');
    }

    #[Test]
    public function captchaVerifierThroughTheGateDecidesLikeThe183Strategy(): void
    {
        $payloads = [
            ['success' => true, 'hostname' => 'blog.example.test'],
            ['success' => true, 'hostname' => 'BLOG.example.test'],
            ['success' => true, 'hostname' => 'evil.example.test'],
            ['success' => true],
            ['success' => false],
            [],
        ];
        foreach ($payloads as $index => $payload) {
            $client = $this->createStub(CaptchaHttpClientInterface::class);
            $client->method('post')->willReturn($payload);
            foreach (['token', ''] as $token) {
                foreach ([['site', 'secret'], ['', 'secret'], ['site', '']] as [$siteKey, $secretKey]) {
                    $stack = new RequestStack();
                    $stack->push(Request::create('https://blog.example.test/blog/x/comments', 'POST', ['cf-turnstile-response' => $token], server: ['REMOTE_ADDR' => '10.0.0.1']));
                    $legacy = new LegacyRemoteCommentCaptchaStrategy(CommentCaptchaStrategy::Turnstile, $client, $stack, $siteKey, $secretKey);
                    $twin   = new BlogCommentCaptchaVerifierGate(new TurnstileVerifier($client, $siteKey, $secretKey), $stack);

                    $case = 'payload ' . $index . ', token "' . $token . '", keys ' . $siteKey . '/' . $secretKey;
                    self::assertSame($this->accepts($legacy), $this->accepts($twin), $case);
                    self::assertSame($legacy->twigContext(), $twin->twigContext(), $case);
                }
            }
        }
    }

    private function outcome(BlogCommentRateLimiterInterface $limiter, Request $request, BlogArticle $article): string
    {
        try {
            $limiter->consume($request, $article);

            return 'allowed';
        } catch (TooManyRequestsHttpException $exception) {
            return 'denied ' . ($exception->getHeaders()['Retry-After'] ?? '') . ' ' . $exception->getMessage();
        }
    }

    private function accepts(BlogCommentCaptchaStrategyInterface $strategy): bool
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class);
        $builder->add('body', TextType::class);
        $strategy->configureForm($builder);
        $form = $builder->getForm();
        $form->submit(['body' => 'x']);

        return $form->isValid();
    }
}
