<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_keys;
use function dirname;
use function file_get_contents;
use function glob;
use function is_array;
use function preg_match_all;
use function sprintf;

/**
 * Regression: constraint messages are translated in the validators domain, not in NowoBlogKitBundle. The slug
 * Regex of the article and tag forms pointed at a key that only existed nowhere, so the admin showed the raw
 * "admin.validation.slug" text. Every dotted constraint message used by a form type must exist in the
 * validators catalogue of every locale the bundle ships.
 */
final class ValidatorMessagesTranslationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function localeProvider(): iterable
    {
        foreach (['en', 'es', 'de', 'fr', 'it', 'nl', 'pt'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function everyDottedConstraintMessageIsTranslated(string $locale): void
    {
        $catalogue = self::flatten($this->load($locale));
        $used      = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Form/*.php') ?: [] as $file) {
            preg_match_all("/message:\\s*'([a-z_]+(?:\\.[a-z_]+)+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                $used[$key] = true;
            }
        }

        self::assertNotSame([], $used, 'The form types declare no dotted constraint message any more: adjust this test.');
        foreach (array_keys($used) as $key) {
            self::assertArrayHasKey($key, $catalogue, sprintf('Constraint message "%s" has no validators.%s.yaml entry', $key, $locale));
            self::assertNotSame('', $catalogue[$key]);
        }
    }

    #[Test]
    public function localesTranslateTheSlugMessage(): void
    {
        $english = self::flatten($this->load('en'))['admin.validation.slug'];
        foreach (['es', 'de', 'fr', 'it', 'nl', 'pt'] as $locale) {
            self::assertNotSame($english, self::flatten($this->load($locale))['admin.validation.slug'], $locale);
        }
    }

    /** @return array<mixed> */
    private function load(string $locale): array
    {
        $data = Yaml::parseFile(dirname(__DIR__, 3) . '/src/Resources/translations/validators.' . $locale . '.yaml');
        self::assertIsArray($data);

        return $data;
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
