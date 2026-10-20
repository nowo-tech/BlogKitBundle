<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function dirname;
use function implode;
use function in_array;
use function is_array;
use function preg_match_all;
use function sort;
use function sprintf;

/**
 * Regression: fr/de/it/nl/pt shipped English copies for ~170 messages per locale. Every non-English
 * catalogue must translate each message; only strings that are legitimately identical in that
 * language (brand names, technical terms, loanwords) may equal the English value.
 */
final class CatalogueTranslationTest extends TestCase
{
    private const string DOMAIN = 'NowoBlogKitBundle';

    /** English values that stay identical in every language (brands, product names, technical terms). */
    private const array UNIVERSAL = [
        'Blog',
        'Slug',
        'LinkedIn',
        'reCAPTCHA v2',
        'reCAPTCHA v3',
        'hCaptcha',
        'Cloudflare Turnstile',
    ];

    /** English values that are the correct word in the given language too. */
    private const array LOCALE_ALLOWLIST = [
        'es' => ['Masonry', 'Honeypot', 'No', 'Hashtags'],
        'fr' => ['Actions', 'Article', 'Articles', 'Date', 'Hashtags', 'Pagination', 'Position', 'Tags'],
        'de' => ['Hashtags', 'Name', 'Position', 'Status', 'Tags'],
        'it' => ['No'],
        'nl' => ['Hashtags', 'Status', 'Tags'],
        'pt' => ['Hashtags'],
    ];

    /** @return iterable<string, array{string}> */
    public static function localeProvider(): iterable
    {
        foreach (array_keys(self::LOCALE_ALLOWLIST) as $locale) {
            yield $locale => [$locale];
        }
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function catalogueHasSameKeysAsEnglish(string $locale): void
    {
        $english    = $this->catalogue('en');
        $translated = $this->catalogue($locale);

        self::assertSame([], array_diff(array_keys($english), array_keys($translated)), sprintf('Missing keys in %s', $locale));
        self::assertSame([], array_diff(array_keys($translated), array_keys($english)), sprintf('Extra keys in %s', $locale));
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function messagesAreNotEnglishCopies(string $locale): void
    {
        $allowed = [...self::UNIVERSAL, ...self::LOCALE_ALLOWLIST[$locale]];
        $copies  = [];
        foreach ($this->catalogue($locale) as $key => $value) {
            $english = $this->catalogue('en')[$key] ?? null;
            if ($value === $english && !in_array($value, $allowed, true)) {
                $copies[] = sprintf('%s: "%s"', $key, $value);
            }
        }

        self::assertSame([], $copies, sprintf("Untranslated (English) messages in %s:\n%s", $locale, implode("\n", $copies)));
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function placeholdersAndHtmlTagsArePreserved(string $locale): void
    {
        $english = $this->catalogue('en');
        foreach ($this->catalogue($locale) as $key => $value) {
            self::assertSame($this->tokens($english[$key] ?? ''), $this->tokens($value), sprintf('%s.%s placeholders/HTML differ from English', $locale, $key));
        }
    }

    /** @return list<string> */
    private function tokens(string $message): array
    {
        preg_match_all('/%[a-z_]+%|\{[a-z_]+\}|<\/?[a-z]+\b[^>]*>/i', $message, $matches);
        $tokens = $matches[0];
        sort($tokens);

        return $tokens;
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        static $cache = [];
        if (!isset($cache[$locale])) {
            $data = Yaml::parseFile(dirname(__DIR__, 3) . '/src/Resources/translations/' . self::DOMAIN . '.' . $locale . '.yaml');
            self::assertIsArray($data);
            $cache[$locale] = self::flatten($data);
        }

        return $cache[$locale];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix . $key . '.');
            } else {
                $flat[$prefix . $key] = (string) $value;
            }
        }

        return $flat;
    }
}
