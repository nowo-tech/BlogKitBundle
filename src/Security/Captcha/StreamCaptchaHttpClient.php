<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use Psr\Log\LoggerInterface;

use function is_array;
use function is_string;
use function parse_url;

use const PHP_URL_HOST;

/**
 * Default CAPTCHA HTTP client using `file_get_contents`.
 *
 * Every call is bounded by `$timeout` (REQ-RUNTIME-001). Failures are logged with the provider
 * host only — never the secret key, the token or the client IP (REQ-OBS-001).
 */
final readonly class StreamCaptchaHttpClient implements CaptchaHttpClientInterface
{
    /**
     * @param (callable(string, array<string, string>): (false|string))|null $transport
     * @param float $timeout Stream read timeout in seconds (DNS resolution is not covered)
     */
    public function __construct(
        private mixed $transport = null,
        private float $timeout = 5.0,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function post(string $url, array $fields): array
    {
        $context = ['bundle' => 'blog_kit', 'action' => 'captcha_verify', 'host' => (string) parse_url($url, PHP_URL_HOST)];

        $raw = $this->transport === null
            ? $this->postWithFileGetContents($url, $fields)
            : ($this->transport)($url, $fields);

        if (!is_string($raw) || $raw === '') {
            $this->logger?->warning('CAPTCHA verification request failed or timed out.', $context + ['timeout_seconds' => $this->timeout]);

            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger?->warning('CAPTCHA verification returned a non-JSON response.', $context);

            return [];
        }

        $this->logger?->info('CAPTCHA verification request completed.', $context);

        return $decoded;
    }

    /**
     * @param array<string, string> $fields
     */
    private function postWithFileGetContents(string $url, array $fields): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'timeout' => $this->timeout,
            ],
        ]);

        return @file_get_contents($url, false, $context);
    }
}
