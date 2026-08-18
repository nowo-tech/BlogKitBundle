<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function is_numeric;

/**
 * Verifies reCAPTCHA v2/v3, hCaptcha, or Cloudflare Turnstile tokens.
 */
final readonly class RemoteCommentCaptchaStrategy implements BlogCommentCaptchaStrategyInterface
{
    private const array ENDPOINTS = [
        'recaptcha_v2' => 'https://www.google.com/recaptcha/api/siteverify',
        'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
        'hcaptcha'     => 'https://hcaptcha.com/siteverify',
        'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ];

    private const array TOKEN_FIELDS = [
        'recaptcha_v2' => 'g-recaptcha-response',
        'recaptcha_v3' => 'g-recaptcha-response',
        'hcaptcha'     => 'h-captcha-response',
        'turnstile'    => 'cf-turnstile-response',
    ];

    private const array WIDGET_CLASSES = [
        'recaptcha_v2' => 'g-recaptcha',
        'recaptcha_v3' => '',
        'hcaptcha'     => 'h-captcha',
        'turnstile'    => 'cf-turnstile',
    ];

    public function __construct(
        private CommentCaptchaStrategy $provider,
        private CaptchaHttpClientInterface $httpClient,
        private RequestStack $requestStack,
        private string $siteKey,
        private string $secretKey,
        private float $minScore = 0.5,
    ) {
    }

    #[Override]
    public function configureForm(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmit(...));
    }

    #[Override]
    public function twigContext(): array
    {
        $value = $this->provider->value;

        return [
            'enabled'      => true,
            'strategy'     => $value,
            'site_key'     => $this->siteKey,
            'script_url'   => $this->scriptUrl(),
            'widget_class' => self::WIDGET_CLASSES[$value] ?? '',
        ];
    }

    private function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        if (!$form->isRoot()) {
            return;
        }

        if ($this->siteKey === '' || $this->secretKey === '' || !$this->isValidToken()) {
            $form->addError(new FormError('blog.comments.error_invalid'));
        }
    }

    private function isValidToken(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return false;
        }

        $field    = self::TOKEN_FIELDS[$this->provider->value] ?? '';
        $token    = $request->request->getString($field);
        $endpoint = self::ENDPOINTS[$this->provider->value] ?? '';
        if ($token === '' || $field === '' || $endpoint === '') {
            return false;
        }

        $payload = $this->httpClient->post($endpoint, [
            'secret'   => $this->secretKey,
            'response' => $token,
            'remoteip' => $request->getClientIp() ?? '',
        ]);

        if (($payload['success'] ?? false) !== true) {
            return false;
        }

        if ($this->provider !== CommentCaptchaStrategy::RecaptchaV3) {
            return true;
        }

        $score = $payload['score'] ?? 0;

        return is_numeric($score) && (float) $score >= $this->minScore;
    }

    private function scriptUrl(): string
    {
        if ($this->siteKey === '') {
            return '';
        }

        return match ($this->provider) {
            CommentCaptchaStrategy::RecaptchaV2 => 'https://www.google.com/recaptcha/api.js',
            CommentCaptchaStrategy::RecaptchaV3 => 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($this->siteKey),
            CommentCaptchaStrategy::Hcaptcha    => 'https://js.hcaptcha.com/1/api.js',
            CommentCaptchaStrategy::Turnstile   => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            default                             => '',
        };
    }
}
