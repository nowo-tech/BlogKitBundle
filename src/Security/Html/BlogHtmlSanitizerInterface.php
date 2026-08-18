<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Security\Html;

/**
 * Sanitizes editor-authored article HTML before persist and public render.
 */
interface BlogHtmlSanitizerInterface
{
    public function sanitize(string $html): string;
}
