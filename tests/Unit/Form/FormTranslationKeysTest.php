<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Form;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleResource;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;
use Nowo\BlogKitBundle\Form\BlogArticleFilterType;
use Nowo\BlogKitBundle\Form\BlogArticleInlineTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleResourceType;
use Nowo\BlogKitBundle\Form\BlogArticleTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleType;
use Nowo\BlogKitBundle\Form\BlogCommentFilterType;
use Nowo\BlogKitBundle\Form\BlogInlineModalType;
use Nowo\BlogKitBundle\Form\BlogPublicSearchType;
use Nowo\BlogKitBundle\Form\BlogSettingsType;
use Nowo\BlogKitBundle\Form\BlogTagFilterType;
use Nowo\BlogKitBundle\Form\BlogTagTranslationType;
use Nowo\BlogKitBundle\Form\BlogTagType;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Form\StaffBlogCommentReplyType;
use Nowo\BlogKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use Nowo\FormKitBundle\Form\Extension\LocaleTabsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

use function array_unique;
use function basename;
use function glob;
use function is_array;
use function is_string;
use function preg_match;
use function sort;
use function sprintf;

/**
 * Regression: every translation key a bundle form renders (label, help, placeholder, choice labels —
 * including FormKit's auto `{form}.{field}.placeholder` / `.help` keys) must exist in every
 * NowoBlogKitBundle.*.yaml catalogue, so no raw key leaks into the admin or public UI.
 */
final class FormTranslationKeysTest extends TestCase
{
    private const string DOMAIN = 'NowoBlogKitBundle';

    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    /** @return iterable<string, array{string}> */
    public static function catalogueProvider(): iterable
    {
        $files = glob(__DIR__ . '/../../../src/Resources/translations/' . self::DOMAIN . '.*.yaml');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    #[Test]
    #[DataProvider('catalogueProvider')]
    public function everyRenderedFormKeyIsTranslated(string $catalogueFile): void
    {
        $parsed = Yaml::parseFile($catalogueFile);
        self::assertIsArray($parsed);
        $catalogue = $this->flatten($parsed);

        $missing = [];
        foreach ($this->collectKeys() as $key) {
            if (!isset($catalogue[$key])) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, sprintf('Missing keys in %s', basename($catalogueFile)));
    }

    /** @return list<string> */
    private function collectKeys(): array
    {
        $types = [
            'settings'           => [BlogSettingsType::class, new BlogSettings(), []],
            'articleTranslation' => [BlogArticleTranslationType::class, new BlogArticleTranslation(), []],
            'inlineTranslation'  => [BlogArticleInlineTranslationType::class, new BlogArticleTranslation(), []],
            'resource'           => [BlogArticleResourceType::class, new BlogArticleResource(), []],
            'inlineModal'        => [BlogInlineModalType::class, new BlogArticle(), [BlogArticleInlineTranslationType::class]],
            'tag'                => [BlogTagType::class, new BlogTag(), [BlogTagTranslationType::class]],
            'tagTranslation'     => [BlogTagTranslationType::class, new BlogTagTranslation(), []],
            'publicSearch'       => [BlogPublicSearchType::class, null, []],
            'publicComment'      => [PublicBlogCommentType::class, null, []],
            'staffReply'         => [StaffBlogCommentReplyType::class, null, []],
            'articleFilter'      => [BlogArticleFilterType::class, null, []],
            'commentFilter'      => [BlogCommentFilterType::class, null, []],
            'tagFilter'          => [BlogTagFilterType::class, null, []],
        ];

        $keys = [];
        foreach ($types as [$class, $data, $extra]) {
            $preloaded = [FormKitTestSupport::createType($class)];
            foreach ($extra as $extraClass) {
                $preloaded[] = FormKitTestSupport::createType($extraClass);
            }
            $factory = Forms::createFormFactoryBuilder()
                ->addExtension(new CsrfExtension(new CsrfTokenManager()))
                ->addTypeExtension(new LocaleTabsExtension())
                ->addExtension(new ValidatorExtension(Validation::createValidator()))
                ->addTypes($preloaded)
                ->getFormFactory();
            $view = $factory->create($class, $data, ['csrf_protection' => false])->createView();
            $this->collectFromView($view, $keys);
        }

        $this->collectFromBlogArticleType($keys);

        $keys = array_unique($keys);
        sort($keys);

        return $keys;
    }

    /** @param list<string> $keys */
    private function collectFromView(FormView $view, array &$keys): void
    {
        if (($view->vars['translation_domain'] ?? null) === self::DOMAIN) {
            foreach (['label', 'help'] as $option) {
                $this->addKey($view->vars[$option] ?? null, $keys);
            }
            $this->addKey($view->vars['attr']['placeholder'] ?? null, $keys);
            $this->addKey($view->vars['placeholder'] ?? null, $keys);
        }

        if (($view->vars['choice_translation_domain'] ?? null) === self::DOMAIN) {
            foreach ($view->vars['choices'] ?? [] as $choice) {
                $this->addKey($choice->label ?? null, $keys);
            }
        }

        foreach ($view->children as $child) {
            $this->collectFromView($child, $keys);
        }

        $prototype = $view->vars['prototype'] ?? null;
        if ($prototype instanceof FormView) {
            $this->collectFromView($prototype, $keys);
        }
    }

    /**
     * BlogArticleType needs Doctrine for its EntityType; capture the merged field options instead.
     *
     * @param list<string> $keys
     */
    private function collectFromBlogArticleType(array &$keys): void
    {
        $type    = FormKitTestSupport::createType(BlogArticleType::class);
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, string $fieldType, array $options) use (&$keys, $builder): FormBuilderInterface {
            if ($name !== 'translations') {
                $this->addKey($options['label'] ?? null, $keys);
                $this->addKey($options['help'] ?? null, $keys);
                $this->addKey($options['attr']['placeholder'] ?? null, $keys);
            }

            return $builder;
        });

        $type->buildForm($builder, []);
    }

    /** @param list<string> $keys */
    private function addKey(mixed $value, array &$keys): void
    {
        // Only dotted catalogue keys (literal copy such as "PHP (php)" is not a key).
        if (is_string($value) && preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $value) === 1) {
            $keys[] = $value;
        }
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
            } else {
                $flat[$full] = true;
            }
        }

        return $flat;
    }
}
