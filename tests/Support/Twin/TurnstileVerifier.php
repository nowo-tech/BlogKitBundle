<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support\Twin;

use Nowo\BlogKitBundle\Security\Captcha\BlogCommentCaptchaVerifierInterface;
use Nowo\BlogKitBundle\Security\Captcha\CaptchaHttpClientInterface;
use Nowo\BlogKitBundle\ValueObject\BlogCommentCaptchaWidget;
use Nowo\BlogKitBundle\ValueObject\BlogCommentRequestContext;

use function is_string;
use function strtolower;

/**
 * The 1.8.3 Turnstile check written against the twin port (no form builder, no Request).
 */
final readonly class TurnstileVerifier implements BlogCommentCaptchaVerifierInterface
{
    public function __construct(
        private CaptchaHttpClientInterface $httpClient,
        private string $siteKey,
        private string $secretKey,
    ) {
    }

    public function widget(): BlogCommentCaptchaWidget
    {
        return new BlogCommentCaptchaWidget(true, 'turnstile', $this->siteKey, $this->siteKey === '' ? '' : 'https://challenges.cloudflare.com/turnstile/v0/api.js', 'cf-turnstile');
    }

    public function verify(BlogCommentRequestContext $request): bool
    {
        $token = $request->field('cf-turnstile-response');
        if ($this->siteKey === '' || $this->secretKey === '' || $token === '') {
            return false;
        }

        $payload = $this->httpClient->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret'   => $this->secretKey,
            'response' => $token,
            'remoteip' => $request->clientIp ?? '',
        ]);
        if (($payload['success'] ?? false) !== true) {
            return false;
        }

        $hostname = $payload['hostname'] ?? null;

        return $hostname === null || (is_string($hostname) && strtolower($hostname) === strtolower($request->host));
    }
}
