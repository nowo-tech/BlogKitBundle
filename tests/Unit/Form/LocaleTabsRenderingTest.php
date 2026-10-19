<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Form;

use Dom\Element;
use Dom\HTMLDocument;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Form\AbstractBlogFormType;
use Nowo\BlogKitBundle\Form\BlogArticleInlineTranslationType;
use Nowo\BlogKitBundle\Form\BlogInlineModalType;
use Nowo\BlogKitBundle\Form\BlogTagTranslationType;
use Nowo\BlogKitBundle\Form\BlogTagType;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\FormKitBundle\Form\Extension\LocaleTabsExtension;
use Nowo\FormKitBundle\Twig\LocaleTabsTwigExtension;
use Nowo\UiKitBundle\Theme\Theme;
use Nowo\UiKitBundle\Twig\ThemeExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

use function array_map;
use function dirname;
use function iterator_to_array;
use function substr_count;

use const LIBXML_NOERROR;

/**
 * Admin translation tabs render through FormKit `locale_tabs` (mode entries) and the UiKit partial.
 */
final class LocaleTabsRenderingTest extends TestCase
{
    #[Test]
    public function pageFormListsTheDefaultLocaleFirstWithHiddenLocaleInputs(): void
    {
        $tag = new BlogTag()->setSlug('php');
        $tag->ensureTranslations(['en', 'es']);
        $form = $this->tagForm($tag);

        $html = $this->render('@NowoBlogKitBundle/admin/_form.html.twig', $form);
        $doc  = HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);

        self::assertSame(['es', 'en'], $this->tabLocales($doc), 'default locale (es) first');
        $root = $doc->querySelector('[data-testid="blog-kit-locale-tabs"] [data-nowo-ui-locale-tabs]');
        self::assertInstanceOf(Element::class, $root);
        self::assertSame('locale', $root->getAttribute('id'));
        self::assertSame('locale-es', $root->getAttribute('data-nowo-ui-tabs-active'));
        $es = $doc->querySelector('#tab-locale-es');
        self::assertInstanceOf(Element::class, $es);
        self::assertTrue($es->hasAttribute('data-default'));
        self::assertTrue($es->hasAttribute('data-required'));
        self::assertSame('0', $es->getAttribute('tabindex'));
        self::assertSame('-1', $doc->querySelector('#tab-locale-en')?->getAttribute('tabindex'));
        self::assertNull($doc->querySelector('#tab-locale-en[data-required]'));

        $hidden = $doc->querySelector('#tab-locale-es-panel input[type="hidden"][name$="[locale]"]');
        self::assertInstanceOf(Element::class, $hidden);
        self::assertSame('es', $hidden->getAttribute('value'));
        self::assertNotNull($doc->querySelector('#tab-locale-en-panel input[name$="[name]"]'));
        self::assertCount(0, $doc->querySelectorAll('input[type="text"][name$="[locale]"]'), 'the locale is never a visible input');
        self::assertSame(1, substr_count($html, 'name="blog_tag[slug]"'));
        self::assertSame(1, substr_count($html, '[translations][0][locale]"'), 'entries rendered once');
        self::assertStringNotContainsString('admin-locale-tabs', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html);
    }

    #[Test]
    public function invalidLocaleIsMarkedAndOpened(): void
    {
        $tag = new BlogTag()->setSlug('php');
        $tag->ensureTranslations(['es', 'en']);
        $form = $this->tagForm($tag);
        $form->submit(['slug' => 'php', 'translations' => [['locale' => 'es', 'name' => 'PHP'], ['locale' => 'en', 'name' => 'x']]]);
        $form->get('translations')->get('1')->get('name')->addError(new FormError('Name missing.'));

        $doc = HTMLDocument::createFromString('<!doctype html><body>' . $this->render('@NowoBlogKitBundle/admin/_form.html.twig', $form), LIBXML_NOERROR);

        self::assertSame('locale-en', $doc->querySelector('[data-nowo-ui-locale-tabs]')?->getAttribute('data-nowo-ui-tabs-active'), 'the invalid tab opens');
        self::assertTrue($doc->querySelector('#tab-locale-en')?->hasAttribute('data-invalid') ?? false);
        self::assertSame('0', $doc->querySelector('#tab-locale-en')?->getAttribute('tabindex'));
        self::assertNull($doc->querySelector('#tab-locale-es[data-invalid]'));
        self::assertSame('true', $doc->querySelector('#tab-locale-en-panel input[name$="[name]"]')?->getAttribute('aria-invalid'));
        self::assertStringContainsString('Name missing.', $doc->querySelector('#tab-locale-en-panel')?->textContent ?? '');
    }

    #[Test]
    public function modalKeepsItsOwnTabsIdAndOpensTheRequestedLocale(): void
    {
        $article = new BlogArticle()->setSlug('hello');
        $article->ensureTranslations(['en', 'es']);
        $types = [FormKitTestSupport::createType(BlogInlineModalType::class), FormKitTestSupport::createType(BlogArticleInlineTranslationType::class)];
        $form  = $this->factory($types)->create(BlogInlineModalType::class, $article);

        $html = $this->render('@NowoBlogKitBundle/admin/_modal_form.html.twig', $form, ['locale' => 'en']);
        $doc  = HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);

        self::assertSame(['es', 'en'], $this->tabLocales($doc));
        self::assertNotNull($doc->querySelector('#blog-kit-cms-modal [data-testid="blog-kit-cms-locale-tabs"] #cms-locale'));
        self::assertSame('cms-locale-en', $doc->querySelector('#cms-locale')?->getAttribute('data-nowo-ui-tabs-active'));
        self::assertNotNull($doc->querySelector('#tab-cms-locale-es-panel textarea[name$="[body]"]'));
        self::assertSame('es', $doc->querySelector('#tab-cms-locale-es-panel input[type="hidden"]')?->getAttribute('value'));
    }

    #[Test]
    public function translationTabsWithoutBlogLocalesFallBackToFormKitDefaults(): void
    {
        $type = FormKitTestSupport::createType(BlogTagType::class);
        $tabs = new ReflectionMethod(AbstractBlogFormType::class, 'translationTabs')->invoke($type);
        self::assertSame(['mode' => 'entries'], $tabs);

        $type->setBlogLocales(new BlogLocales('en', ['en', 'es']));
        self::assertSame(['mode' => 'entries', 'default_locale' => 'en', 'required_locales' => ['en']], new ReflectionMethod(AbstractBlogFormType::class, 'translationTabs')->invoke($type));
    }

    /** @return FormInterface<mixed> */
    private function tagForm(BlogTag $blogTag): FormInterface
    {
        $types = [FormKitTestSupport::createType(BlogTagType::class), FormKitTestSupport::createType(BlogTagTranslationType::class)];

        return $this->factory($types)->create(BlogTagType::class, $blogTag);
    }

    /** @param list<object> $types */
    private function factory(array $types): FormFactoryInterface
    {
        foreach ($types as $type) {
            if ($type instanceof AbstractBlogFormType) {
                $type->setBlogLocales(new BlogLocales('es', ['es', 'en']));
            }
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new LocaleTabsExtension())
            ->addTypes($types)
            ->getFormFactory();
    }

    /** @return list<string> */
    private function tabLocales(HTMLDocument $doc): array
    {
        return array_map(static fn (Element $e): string => (string) $e->getAttribute('data-locale'), iterator_to_array($doc->querySelectorAll('[role="tab"]')));
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $context
     */
    private function render(string $template, FormInterface $form, array $context = []): string
    {
        $root = dirname(__DIR__, 3);
        $fs   = new FilesystemLoader([$root . '/vendor/symfony/twig-bridge/Resources/views/Form']);
        $fs->addPath($root . '/src/Resources/views', 'NowoBlogKitBundle');
        $fs->addPath($root . '/vendor/nowo-tech/form-kit-bundle/src/Resources/views', 'NowoFormKitBundle');
        $fs->addPath($root . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKitBundle');
        $fs->addPath($root . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKit');

        $twig = new Environment($fs, ['strict_variables' => false, 'cache' => false]);
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new LocaleTabsTwigExtension());
        $twig->addExtension(new ThemeExtension(new Theme()));
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => '/' . $route));

        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));

        return $twig->render($template, ['form' => $form->createView(), ...$context]);
    }
}
