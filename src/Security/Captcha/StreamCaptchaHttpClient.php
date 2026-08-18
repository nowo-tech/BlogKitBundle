<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

use function is_array;
use function is_string;

/**
 * Default CAPTCHA HTTP client using `file_get_contents`.
 */
final readonly class StreamCaptchaHttpClient implements CaptchaHttpClientInterface
{
    /** @param (callable(string, array<string, string>): (false|string))|null $transport */
    public function __construct(
        private mixed $transport = null,
    ) {
    }

    public function post(string $url, array $fields): array
    {
        $raw = $this->transport === null
            ? $this->postWithFileGetContents($url, $fields)
            : ($this->transport)($url, $fields);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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
                'timeout' => 5,
            ],
        ]);

        return @file_get_contents($url, false, $context);
    }
}
