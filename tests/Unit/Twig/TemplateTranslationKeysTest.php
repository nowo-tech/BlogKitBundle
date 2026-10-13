<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function is_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function strlen;
use function substr;

use const PREG_SET_ORDER;

/**
 * Regression: included partials do not inherit the caller's trans_default_domain, so every bundle
 * template that translates must declare the NowoBlogKitBundle domain, and every literal key it
 * translates must exist in each shipped catalogue (no raw "admin.audit.updated_at" in the UI).
 */
final class TemplateTranslationKeysTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templateProvider(): iterable
    {
        foreach (self::templates() as $relative => $path) {
            yield $relative => [$path];
        }
    }

    #[Test]
    #[DataProvider('templateProvider')]
    public function translatingTemplateDeclaresBundleDomain(string $path): void
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        if (preg_match('/\|\s*trans\b(?!\s*\()/', $content) !== 1) {
            $this->addToAssertionCount(1);

            return;
        }

        self::assertStringContainsString("{% trans_default_domain 'NowoBlogKitBundle' %}", $content);
    }

    /** @return iterable<string, array{string}> */
    public static function catalogueProvider(): iterable
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Resources/translations/NowoBlogKitBundle.*.yaml');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    #[Test]
    #[DataProvider('catalogueProvider')]
    public function literalTemplateKeysExistInCatalogue(string $catalogueFile): void
    {
        $parsed = Yaml::parseFile($catalogueFile);
        self::assertIsArray($parsed);
        $catalogue = $this->flatten($parsed);

        $missing = [];
        foreach (self::templates() as $relative => $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content);
            preg_match_all("/'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\s*\\|\\s*trans\\b(?!\\s*\\(\\s*\\{[^}]*\\}\\s*,)/", $content, $matches);
            foreach ($matches[1] as $key) {
                if (!isset($catalogue[$key])) {
                    $missing[] = $relative . ': ' . $key;
                }
            }
        }
        sort($missing);

        self::assertSame([], $missing, sprintf('Missing keys in %s', basename($catalogueFile)));
    }

    /**
     * Regression: a translation that drops a parameter the template passes renders a bare word
     * (e.g. admin pagination showed "Page" instead of "Page 1 of 2").
     */
    #[Test]
    #[DataProvider('catalogueProvider')]
    public function parameterisedTemplateKeysKeepTheirPlaceholders(string $catalogueFile): void
    {
        $parsed = Yaml::parseFile($catalogueFile);
        self::assertIsArray($parsed);
        $messages = $this->flattenMessages($parsed);

        $problems = [];
        foreach (self::templates() as $relative => $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content);
            preg_match_all("/'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\s*\\|\\s*trans\\s*\\(\\s*\\{([^}]*)\\}/", $content, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $key, $parameters]) {
                if (!isset($messages[$key])) {
                    continue;
                }

                preg_match_all("/'(%[a-z_]+%)'\\s*:/", $parameters, $placeholders);
                foreach ($placeholders[1] as $placeholder) {
                    if (!str_contains($messages[$key], $placeholder)) {
                        $problems[] = sprintf('%s: %s lacks %s', $relative, $key, $placeholder);
                    }
                }
            }
        }
        sort($problems);

        self::assertSame([], $problems, sprintf('Dropped parameters in %s', basename($catalogueFile)));
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flattenMessages(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += $this->flattenMessages($value, $full);
            } elseif (is_string($value)) {
                $flat[$full] = $value;
            }
        }

        return $flat;
    }

    /** @return array<string, string> */
    private static function templates(): array
    {
        $root      = dirname(__DIR__, 3) . '/src/Resources/views/';
        $templates = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '.twig')) {
                $templates[substr($path, strlen($root))] = $path;
            }
        }
        ksort($templates);

        return $templates;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, true>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $full);
            } elseif (!str_contains($full, ' ')) {
                $flat[$full] = true;
            }
        }

        return $flat;
    }
}
