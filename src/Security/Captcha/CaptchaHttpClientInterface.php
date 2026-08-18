<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Captcha;

/**
 * Posts form-encoded fields to a CAPTCHA provider and returns the JSON map.
 */
interface CaptchaHttpClientInterface
{
    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    public function post(string $url, array $fields): array;
}
