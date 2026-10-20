<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Security\Captcha;

use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\Security\Captcha\HoneypotCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Captcha\NoneCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Captcha\PublicBlogCommentCaptchaTypeExtension;
use Nowo\BlogKitBundle\Security\Captcha\RemoteCommentCaptchaStrategy;
use Nowo\BlogKitBundle\Security\Captcha\StreamCaptchaHttpClient;
use Nowo\BlogKitBundle\Tests\Support\BlogProtectionTestFactory;
use Nowo\BlogKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\BlogKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Validation;

use const JSON_THROW_ON_ERROR;

final class CommentCaptchaStrategyTest extends TestCase
{
    #[Test]
    public function noneStrategyIsDisabled(): void
    {
        $strategy = new NoneCommentCaptchaStrategy();
        $builder  = Forms::createFormFactory()->createBuilder();
        $strategy->configureForm($builder);

        self::assertFalse($strategy->twigContext()['enabled']);
        self::assertSame('none', $strategy->twigContext()['strategy']);
        self::assertSame([], $builder->all());
    }

    #[Test]
    public function honeypotAddsBlankConstrainedFieldAndFallsBackToWebsite(): void
    {
        $strategy = new HoneypotCommentCaptchaStrategy('');
        $builder  = Forms::createFormFactory()->createBuilder();
        $strategy->configureForm($builder);
        $form = $builder->getForm();

        self::assertTrue($form->has('website'));
        self::assertTrue($strategy->twigContext()['enabled']);
        self::assertSame('honeypot', $strategy->twigContext()['strategy']);

        $form->submit(['website' => 'http://spam.test']);
        self::assertFalse($form->isValid());

        $cleanBuilder = Forms::createFormFactory()->createBuilder();
        $strategy->configureForm($cleanBuilder);
        $clean = $cleanBuilder->getForm();
        $clean->submit(['website' => '']);
        self::assertTrue($clean->isValid());

        $named        = new HoneypotCommentCaptchaStrategy('company');
        $namedBuilder = Forms::createFormFactory()->createBuilder();
        $named->configureForm($namedBuilder);
        $namedForm = $namedBuilder->getForm();
        self::assertTrue($namedForm->has('company'));
        $namedForm->submit([]);
        self::assertTrue($namedForm->isValid());
    }

    #[Test]
    public function remoteRejectsMissingKeysAndMissingRequest(): void
    {
        $strategy = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV2,
            $this->http(['success' => true]),
            new RequestStack(),
            '',
            '',
        );

        $form = $this->submittedRootForm($strategy);
        self::assertFalse($form->isValid());
        self::assertSame('', $strategy->twigContext()['script_url']);
        self::assertSame('g-recaptcha', $strategy->twigContext()['widget_class']);
    }

    #[Test]
    public function remoteAcceptsValidV2Token(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', 'POST', ['g-recaptcha-response' => 'token']));
        $strategy = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV2,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );

        self::assertTrue($this->submittedRootForm($strategy)->isValid());
        self::assertSame('https://www.google.com/recaptcha/api.js', $strategy->twigContext()['script_url']);
    }

    #[Test]
    public function remoteV3RequiresMinimumScoreAndAcceptsHighScore(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', 'POST', ['g-recaptcha-response' => 'token']));

        $low = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV3,
            $this->http(['success' => true, 'score' => 0.1]),
            $stack,
            'site',
            'secret',
            0.5,
        );
        self::assertFalse($this->submittedRootForm($low)->isValid());
        self::assertStringContainsString('render=site', $low->twigContext()['script_url']);

        $high = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV3,
            $this->http(['success' => true, 'score' => 0.9]),
            $stack,
            'site',
            'secret',
        );
        self::assertTrue($this->submittedRootForm($high)->isValid());

        $badScore = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV3,
            $this->http(['success' => true, 'score' => 'nope']),
            $stack,
            'site',
            'secret',
        );
        self::assertFalse($this->submittedRootForm($badScore)->isValid());
    }

    #[Test]
    public function remoteExposesHcaptchaAndTurnstileScriptsAndEmptyForUnsupportedProvider(): void
    {
        $stack    = new RequestStack();
        $hcaptcha = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::Hcaptcha,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );
        self::assertSame('https://js.hcaptcha.com/1/api.js', $hcaptcha->twigContext()['script_url']);
        self::assertSame('h-captcha', $hcaptcha->twigContext()['widget_class']);

        $turnstile = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::Turnstile,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/api.js', $turnstile->twigContext()['script_url']);

        $service = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::Service,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );
        self::assertSame('', $service->twigContext()['script_url']);
        self::assertFalse($this->submittedRootForm($service)->isValid());
    }

    #[Test]
    public function remoteFailsWithoutTokenOrUnsuccessfulPayload(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', 'POST'));
        $missing = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::Turnstile,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );
        self::assertFalse($this->submittedRootForm($missing)->isValid());

        $stack->pop();
        $stack->push(Request::create('/', 'POST', ['cf-turnstile-response' => 'tok']));
        $failed = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::Turnstile,
            $this->http(['success' => false]),
            $stack,
            'site',
            'secret',
        );
        self::assertFalse($this->submittedRootForm($failed)->isValid());
    }

    #[Test]
    public function remoteFailsClosedWhenSecretMissingOrPayloadEmpty(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', 'POST', ['g-recaptcha-response' => 'token']));

        $noSecret = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV2,
            $this->http(['success' => true]),
            $stack,
            'site',
            '',
        );
        self::assertFalse($this->submittedRootForm($noSecret)->isValid());

        $emptyPayload = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV2,
            new StreamCaptchaHttpClient(static fn (): string => ''),
            $stack,
            'site',
            'secret',
        );
        self::assertFalse($this->submittedRootForm($emptyPayload)->isValid());
    }

    #[Test]
    public function remoteAcceptsHcaptchaAndTurnstileTokens(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', 'POST', ['h-captcha-response' => 'tok']));
        $hcaptcha = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::Hcaptcha,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );
        self::assertTrue($this->submittedRootForm($hcaptcha)->isValid());

        $stack->pop();
        $stack->push(Request::create('/', 'POST', ['cf-turnstile-response' => 'tok']));
        $turnstile = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::Turnstile,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );
        self::assertTrue($this->submittedRootForm($turnstile)->isValid());
    }

    /**
     * @return iterable<string, array{CommentCaptchaStrategy, string, array<string, mixed>, bool}>
     */
    public static function remoteHostnameAndActionPayloads(): iterable
    {
        foreach ([
            [CommentCaptchaStrategy::RecaptchaV2, 'g-recaptcha-response'],
            [CommentCaptchaStrategy::RecaptchaV3, 'g-recaptcha-response'],
            [CommentCaptchaStrategy::Hcaptcha, 'h-captcha-response'],
            [CommentCaptchaStrategy::Turnstile, 'cf-turnstile-response'],
        ] as [$provider, $field]) {
            $base = ['success' => true, 'score' => 0.9, 'action' => RemoteCommentCaptchaStrategy::RECAPTCHA_V3_ACTION];

            yield $provider->value . ' token solved on another host' => [$provider, $field, $base + ['hostname' => 'evil.example'], false];
            yield $provider->value . ' empty hostname' => [$provider, $field, $base + ['hostname' => ''], false];
            yield $provider->value . ' non-string hostname' => [$provider, $field, $base + ['hostname' => 123], false];
            yield $provider->value . ' matching hostname (case-insensitive)' => [$provider, $field, $base + ['hostname' => 'Blog.Example.COM'], true];
            yield $provider->value . ' no hostname returned' => [$provider, $field, $base, true];
        }

        $v3 = ['success' => true, 'score' => 0.9, 'hostname' => 'blog.example.com'];
        yield 'recaptcha_v3 other action' => [CommentCaptchaStrategy::RecaptchaV3, 'g-recaptcha-response', $v3 + ['action' => 'login'], false];
        yield 'recaptcha_v3 no action returned' => [CommentCaptchaStrategy::RecaptchaV3, 'g-recaptcha-response', $v3, true];
        yield 'recaptcha_v2 ignores action' => [CommentCaptchaStrategy::RecaptchaV2, 'g-recaptcha-response', $v3 + ['action' => 'login'], true];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('remoteHostnameAndActionPayloads')]
    public function remoteChecksSiteverifyHostnameAndV3Action(CommentCaptchaStrategy $provider, string $field, array $payload, bool $valid): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('https://blog.example.com/blog/post', 'POST', [$field => 'token']));
        $strategy = new RemoteCommentCaptchaStrategy($provider, $this->http($payload), $stack, 'site', 'secret');

        self::assertSame($valid, $this->submittedRootForm($strategy)->isValid());
    }

    #[Test]
    public function remoteListenerIgnoresNestedForms(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', 'POST', ['g-recaptcha-response' => 'token']));
        $strategy = new RemoteCommentCaptchaStrategy(
            CommentCaptchaStrategy::RecaptchaV2,
            $this->http(['success' => true]),
            $stack,
            'site',
            'secret',
        );

        $factory = Forms::createFormFactory();
        $root    = $factory->createBuilder();
        $child   = $root->create('child', null, ['compound' => true]);
        $strategy->configureForm($child);
        $root->add($child);
        $form = $root->getForm();
        $form->submit(['child' => []]);

        self::assertTrue($form->isValid());
    }

    #[Test]
    public function streamClientDecodesJsonAndHandlesFailures(): void
    {
        $ok = new StreamCaptchaHttpClient(static fn (): string => '{"success":true}');
        self::assertTrue($ok->post('https://example.test', [])['success']);

        $empty = new StreamCaptchaHttpClient(static fn (): string => '');
        self::assertSame([], $empty->post('https://example.test', []));

        $false = new StreamCaptchaHttpClient(static fn (): false => false);
        self::assertSame([], $false->post('https://example.test', []));

        $invalid = new StreamCaptchaHttpClient(static fn (): string => 'nope');
        self::assertSame([], $invalid->post('https://example.test', []));

        $tmp = tempnam(sys_get_temp_dir(), 'cap');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, '{"success":true,"via":"file"}');
        $native = new StreamCaptchaHttpClient();
        self::assertSame('file', $native->post($tmp, ['secret' => 'x'])['via']);
        self::assertSame([], $native->post('/no/such/captcha-file', []));
        unlink($tmp);
    }

    #[Test]
    public function streamClientLogsOutcomeWithoutSecrets(): void
    {
        $logger = new RecordingLogger();

        new StreamCaptchaHttpClient(static fn (): string => '{"success":true}', 5.0, $logger)
            ->post('https://hcaptcha.com/siteverify', ['secret' => 's3cr3t', 'response' => 'tok']);
        new StreamCaptchaHttpClient(static fn (): string => 'nope', 5.0, $logger)
            ->post('https://hcaptcha.com/siteverify', ['secret' => 's3cr3t', 'response' => 'tok']);
        new StreamCaptchaHttpClient(static fn (): false => false, 5.0, $logger)
            ->post('https://hcaptcha.com/siteverify', ['secret' => 's3cr3t', 'response' => 'tok']);

        self::assertSame(['info', 'warning', 'warning'], array_column($logger->records, 'level'));
        foreach ($logger->records as $record) {
            self::assertSame('hcaptcha.com', $record['context']['host']);
            self::assertStringNotContainsString('s3cr3t', json_encode($record, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('tok', json_encode($record['context'], JSON_THROW_ON_ERROR));
        }
        self::assertSame(5.0, $logger->records[2]['context']['timeout_seconds']);
    }

    /**
     * REQ-RUNTIME-001: a siteverify endpoint that accepts the connection but never answers must not
     * pin the PHP thread — the stream timeout fires and verification fails closed.
     */
    #[Test]
    public function streamClientTimesOutOnUnresponsiveEndpoint(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped('Cannot open a local TCP socket: ' . $errstr);
        }

        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);

        $logger = new RecordingLogger();
        $client = new StreamCaptchaHttpClient(timeout: 0.5, logger: $logger);

        $started = microtime(true);
        $result  = $client->post('http://' . $address . '/siteverify', ['secret' => 'x', 'response' => 'y']);
        $elapsed = microtime(true) - $started;
        fclose($server);

        self::assertSame([], $result);
        self::assertLessThan(5.0, $elapsed);
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
    }

    #[Test]
    public function typeExtensionAddsRecaptchaV3AttrsAndSkipsWhenSiteKeyMissing(): void
    {
        $withKey = new PublicBlogCommentCaptchaTypeExtension(BlogProtectionTestFactory::create([
            'captchaStrategy' => CommentCaptchaStrategy::RecaptchaV3,
            'siteKey'         => 'abc',
            'secretKey'       => 'def',
        ]));
        self::assertSame([PublicBlogCommentType::class], iterator_to_array($withKey::getExtendedTypes()));

        $view = $this->commentFormView($withKey);
        self::assertSame('recaptcha_v3', $view->vars['attr']['data-blog-captcha']);
        self::assertSame('abc', $view->vars['attr']['data-blog-captcha-sitekey']);
        self::assertSame('blog_comment', $view->vars['attr']['data-blog-captcha-action']);

        $withoutKey = new PublicBlogCommentCaptchaTypeExtension(BlogProtectionTestFactory::create([
            'captchaStrategy' => CommentCaptchaStrategy::RecaptchaV3,
        ]));
        $plain = $this->commentFormView($withoutKey);
        self::assertArrayNotHasKey('data-blog-captcha', $plain->vars['attr']);
    }

    /**
     * @return array<string, mixed>
     */
    private function http(array $payload): CaptchaHttpClientInterface
    {
        return new StreamCaptchaHttpClient(static fn (): string => json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function submittedRootForm(RemoteCommentCaptchaStrategy $strategy): FormInterface
    {
        $builder = Forms::createFormFactory()->createBuilder();
        $strategy->configureForm($builder);
        $form = $builder->getForm();
        $form->submit([]);

        return $form;
    }

    private function commentFormView(PublicBlogCommentCaptchaTypeExtension $extension): FormView
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new PreloadedExtension(
                [FormKitTestSupport::createType(PublicBlogCommentType::class)],
                [PublicBlogCommentType::class => [$extension]],
            ))
            ->getFormFactory();

        return $factory->create(PublicBlogCommentType::class)->createView();
    }
}
